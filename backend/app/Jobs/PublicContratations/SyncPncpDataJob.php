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

    // Se a API do governo cair, ele tenta rodar esse job até 5 vezes
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

        $tamanhoPagina = 50; // Máximo aceito da api do PNCP

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

        // Cria os N Jobs Filhos (Operários) e joga na fila, escalonando no tempo (ex: a cada 20 segundos)
        for ($pagina = 1; $pagina <= $totalPaginas; $pagina++) {
            $delayInSeconds = ($pagina - 1) * 20;
            ProcessPncpPageJob::dispatch($this->dto, $pagina, $tamanhoPagina)
                ->delay(now()->addSeconds($delayInSeconds));
        }

        Log::info("SyncPncpDataJob (MASTER) dispatched {$totalPaginas} ProcessPncpPageJobs successfully.");
    }
}
