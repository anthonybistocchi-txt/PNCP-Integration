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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        return now()->addHours((int) config('pncp.retry_until_hours.pca', 12));
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

            // /consolidado/unidades traz poder/esfera e codigo/nome, mas NÃO traz
            // UF/IBGE/município. Esses campos vivem em unidadeOrgao das
            // contratações — enriquecemos sem HTTP extra.
            $geoByCodigo = $this->geoFromContratacoes();

            $unidadesData = [];
            foreach ($unidades as $unidade) {
                $codigoUnidade = (string) ($unidade->codigoUnidade ?? '');
                $geo = $geoByCodigo->get($codigoUnidade);

                $unidadesData[] = new PncpOrgaoUnidadeData(
                    cnpjOrgao: $this->cnpj,
                    anoPca: $this->ano,
                    codigoUnidade: $codigoUnidade,
                    nomeUnidade: $unidade->nomeUnidade ?? null,
                    ufSigla: $unidade->ufSigla ?? $geo?->uf_sigla,
                    codigoIbge: $this->intOrNull(
                        $unidade->codigoIbge ?? $geo?->codigo_ibge ?? null
                    ),
                    nomeMunicipio: $unidade->nomeMunicipio
                        ?? $unidade->municipioNome
                        ?? $geo?->nome_municipio,
                    // API PCA: `poder`/`esfera`; contratações: `poderId`/`esferaId`.
                    poder: $unidade->poder ?? $unidade->poderId ?? null,
                    esfera: $unidade->esfera ?? $unidade->esferaId ?? null,
                    payloadCompleto: $unidade
                );
            }

            $syncAction->handle($unidadesData);

            Log::info('PNCP_UNIDADES_DISCOVERED', [
                'cnpj' => $this->cnpj,
                'ano' => $this->ano,
                'count' => count($unidadesData),
                'geo_enriched' => $geoByCodigo->count(),
            ]);
        } catch (\Throwable $error) {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action' => 'DiscoverPncpUnidadesJob',
                'cnpj' => $this->cnpj,
                'attempt' => $this->attempts(),
            ]);
        }
    }

    /**
     * Mapa codigoUnidade → UF/IBGE/município a partir do payload das contratações
     * deste órgão (registro mais recente por unidade).
     *
     * @return Collection<string, object{uf_sigla: ?string, codigo_ibge: ?string, nome_municipio: ?string}>
     */
    private function geoFromContratacoes(): Collection
    {
        return DB::table('contratacoes')
            ->selectRaw("
                DISTINCT ON (payload_completo->'unidadeOrgao'->>'codigoUnidade')
                payload_completo->'unidadeOrgao'->>'codigoUnidade' AS codigo_unidade,
                payload_completo->'unidadeOrgao'->>'ufSigla' AS uf_sigla,
                payload_completo->'unidadeOrgao'->>'codigoIbge' AS codigo_ibge,
                payload_completo->'unidadeOrgao'->>'municipioNome' AS nome_municipio
            ")
            ->where('orgao_cnpj', $this->cnpj)
            ->whereRaw("payload_completo->'unidadeOrgao'->>'codigoUnidade' IS NOT NULL")
            ->orderByRaw("payload_completo->'unidadeOrgao'->>'codigoUnidade'")
            ->orderByRaw('data_atualizacao_global DESC NULLS LAST')
            ->get()
            ->keyBy('codigo_unidade');
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
