<?php

namespace App\Jobs\Pncp;

use App\Actions\Pncp\SyncPncpOrgaoUnidadesAction;
use App\DTOs\Pncp\PncpOrgaoUnidadeData;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Support\ErrorHandling\PncpFailureHandler;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DiscoverPncpUnidadesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $backoff = [60, 180, 300];

    public function __construct(
        public string $cnpj,
        public int $ano
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours.listagem', 12));
    }

    public function handle(
        PncpClient $client,
        SyncPncpOrgaoUnidadesAction $syncAction,
        PncpFailureHandler $failureHandler
    ): void {
        if ($failureHandler->shouldWait($this, "unidades_{$this->cnpj}", PncpCircuitBreaker::SCOPE_PCA)) {
            return;
        }

        try {
            $unidades = $client->getPcaUnidades($this->cnpj, $this->ano);

            if (empty($unidades)) {
                Log::info('PNCP_UNIDADES_DISCOVERED_EMPTY', [
                    'cnpj' => $this->cnpj,
                    'ano' => $this->ano,
                ]);
                return;
            }

            $unidadesData = [];
            foreach ($unidades as $unidade) {
                $unidadesData[] = new PncpOrgaoUnidadeData(
                    cnpjOrgao: $this->cnpj,
                    codigoUnidade: $unidade['codigoUnidade'] ?? '',
                    nomeUnidade: $unidade['nomeUnidade'] ?? null,
                    ufSigla: $unidade['ufSigla'] ?? null,
                    codigoIbge: isset($unidade['codigoIbge']) ? (int) $unidade['codigoIbge'] : null,
                    nomeMunicipio: $unidade['nomeMunicipio'] ?? null,
                    poderId: $unidade['poderId'] ?? null,
                    esferaId: $unidade['esferaId'] ?? null,
                    payloadCompleto: $unidade
                );
            }

            $syncAction->handle($unidadesData);

            Log::info('PNCP_UNIDADES_DISCOVERED', [
                'cnpj' => $this->cnpj,
                'ano' => $this->ano,
                'count' => count($unidadesData)
            ]);

        } catch (\Throwable $error) {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action' => 'DiscoverPncpUnidadesJob',
                'cnpj' => $this->cnpj,
                'attempt' => $this->attempts(),
            ]);
        }
    }
}
