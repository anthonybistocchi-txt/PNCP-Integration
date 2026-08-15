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

class ProcessPncpPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Tempo de tolerância generoso, mas como é só uma página, vai rodar em segundos
    public $timeout = 120;

    public $tries = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected GetPublicContratationsDTO $dto,
        protected int $pagina,
        protected int $tamanhoPagina
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PncpClient $client, SyncPublicContratationAction $syncAction): void
    {
        Log::info("ProcessPncpPageJob started for page {$this->pagina}.");

        $queryParams = array_filter([
            'dataInicial'                 => $this->dto->dataInicial,
            'dataFinal'                   => $this->dto->dataFinal,
            'codigoModalidadeContratacao' => $this->dto->codigoModalidadeContratacao,
            'uf'                          => $this->dto->uf,
            'codigoMunicipioIbge'         => $this->dto->codigoMunicipioIbge,
            'pagina'                      => $this->pagina,
            'tamanhoPagina'               => $this->tamanhoPagina,
        ]);

        $response = $client->getContratacoes($queryParams);

        if (!$response || empty($response->data)) {
            Log::info("ProcessPncpPageJob finished. No data on page {$this->pagina}.");
            return;
        }

        // Passa o array completo para a Action fazer o Bulk Upsert
        $syncAction->handle($response->data);

        Log::info("ProcessPncpPageJob finished. Processed " . count($response->data) . " items on page {$this->pagina}.");
    }
}
