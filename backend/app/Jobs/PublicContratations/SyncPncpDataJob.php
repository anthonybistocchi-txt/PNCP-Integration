<?php

namespace App\Jobs\PublicContratations;

use App\Actions\PublicContratations\SyncPublicContratationAction;
use App\DTOs\PublicContratations\GetPublicContratationsDTO;
use App\Integrations\PNCP\PncpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Jobs\PublicContratations\ProcessPncpPageJob;

class SyncPncpDataJob implements ShouldQueue
{
    // Dá 5 minutos (300 segundos) de tolerância antes de matar o Job
    public $timeout = 300;

    // Se a API do governo cair, ele tenta rodar esse job até 3 vezes
    public $tries = 5;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected GetPublicContratationsDTO $dto
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PncpClient $client): void
    {
        Log::info('SyncPncpDataJob (MASTER) started for parameters', (array) $this->dto);

        $tamanhoPagina = 500; // Máximo aceito/recomendado para bulk sync

        // Faz apenas UMA requisição para a primeira página para descobrir o Total de Páginas
        $queryParams = array_filter([
            'dataInicial'                 => $this->dto->dataInicial,
            'dataFinal'                   => $this->dto->dataFinal,
            'codigoModalidadeContratacao' => $this->dto->codigoModalidadeContratacao,
            'uf'                          => $this->dto->uf,
            'codigoMunicipioIbge'         => $this->dto->codigoMunicipioIbge,
            'pagina'                      => 1,
            'tamanhoPagina'               => $tamanhoPagina,
        ]);

        $response = $client->getContratacoes($queryParams);

        if (!$response || empty($response->data)) {
            Log::info('SyncPncpDataJob (MASTER) finished. No records found to sync.');
            return;
        }

        $totalPaginas = $response->totalPaginas ?? 1;
        Log::info("PNCP API reports {$response->totalRegistros} total records across {$totalPaginas} pages.");

        // Cria os N Jobs Filhos (Operários) e joga na fila
        for ($pagina = 1; $pagina <= $totalPaginas; $pagina++) {
            ProcessPncpPageJob::dispatch($this->dto, $pagina, $tamanhoPagina);
        }

        Log::info("SyncPncpDataJob (MASTER) dispatched {$totalPaginas} ProcessPncpPageJobs successfully.");
    }
}
