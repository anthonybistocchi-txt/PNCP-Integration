<?php

namespace App\Jobs\Pncp;

use App\Actions\Pncp\SyncPncpOrgaoUnidadesAction;
use App\DTOs\Pncp\PncpOrgaoUnidadeData;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Repositories\Contratacoes\ContratacaoRepository;
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
        return now()->addHours((int) config('pncp.retry_until_hours.pca', 72));
    }

    public function handle(
        PncpClient $client,
        SyncPncpOrgaoUnidadesAction $syncAction,
        PncpFailureHandler $failureHandler,
        ContratacaoRepository $contratacoes
    ): void {

        if ($failureHandler->shouldWait($this, "unidades_{$this->cnpj}", PncpCircuitBreaker::SCOPE_PCA))
        {
            // se deve esperar, retorna
            return;
        }

        try
        {
            $unidades = $client->getPcaUnidades($this->cnpj, $this->ano);

            if (empty($unidades))
            {
                // Log::info('PNCP_UNIDADES_DISCOVERED_EMPTY', [
                //     'cnpj' => $this->cnpj,
                //     'ano' => $this->ano,
                // ]);

                return;
            }

            // getPcaUnidades() traz poder/esfera e codigo/nome, mas NÃO traz
            // UF/IBGE/município. Esses campos vivem em unidadeOrgao das
            // contratações — enriquecemos sem HTTP extra apenas buscando no banco de dados.
            $geoByCodigo = $contratacoes->geoByCodigoUnidade($this->cnpj);

            $unidadesData = [];
            foreach ($unidades as $unidade)
            {
                $codigoUnidade = (string) ($unidade->codigoUnidade ?? '');
                $geo           = $geoByCodigo->get($codigoUnidade);

                $unidadesData[] = new PncpOrgaoUnidadeData(
                    cnpjOrgao:     $this->cnpj,
                    anoPca:        $this->ano,
                    codigoUnidade: $codigoUnidade,
                    nomeUnidade:   $unidade->nomeUnidade ?? null,
                    ufSigla:       $unidade->ufSigla ?? $geo?->uf_sigla,
                    codigoIbge:    $this->intOrNull($unidade->codigoIbge ?? $geo?->codigo_ibge ?? null),
                    nomeMunicipio: $unidade->nomeMunicipio
                        ?? $unidade->municipioNome
                        ?? $geo?->nome_municipio,
                    // API PCA: `poder`/`esfera`; contratações: `poderId`/`esferaId`.
                    poder:           $unidade->poder ?? $unidade->poderId ?? null, // se não tiver, usa o da contratação
                    esfera:          $unidade->esfera ?? $unidade->esferaId ?? null, // se não tiver, usa o da contratação
                    payloadCompleto: $unidade
                );
            }

            $syncAction->handle($unidadesData);

            Log::info('PNCP_UNIDADES_DISCOVERED', [
                'cnpj'         => $this->cnpj,
                'ano'          => $this->ano,
                'count'        => count($unidadesData),
                'geo_enriched' => $geoByCodigo->count(),
            ]);

        }
        catch (\Throwable $error)
        {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action'  => 'DiscoverPncpUnidadesJob',
                'cnpj'    => $this->cnpj,
                'attempt' => $this->attempts(),
            ]);
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '')
        {
            return null;
        }

        return (int) $value;
    }
}
