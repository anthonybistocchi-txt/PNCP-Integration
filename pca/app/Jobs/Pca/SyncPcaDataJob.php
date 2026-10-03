<?php

namespace App\Jobs\Pca;

use App\Actions\Pca\SyncPcaPlanAction;
use App\DTOs\Pca\PcaPlanData;
use App\DTOs\Pca\PcaSyncParamsDTO;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Repositories\Orgaos\OrgaoRepository;
use App\Repositories\Pca\PcaPlanRepository;
use App\Repositories\Pca\PncpPcaCicloRepository;
use App\Support\ErrorHandling\PncpFailureHandler;
use App\Support\Pca\PcaItemsFreshness;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncPcaDataJob implements ShouldQueue, ShouldBeUnique
{
    public $timeout = 300;
    public $backoff = [120, 300, 600];

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $syncId = '';

    public function __construct(
        protected PcaSyncParamsDTO $dto
    ) {}

    public function uniqueId(): string
    {
        return "{$this->dto->ano}:{$this->dto->cnpj}";
    }

    /**
     * Acompanha o prazo do job (era 12h fixas). O lock é liberado quando o
     * mestre termina; este teto só vale se ele ficar esperando na fila.
     */
    public function uniqueFor(): int
    {
        return (int) config('pncp.retry_until_hours.pca', 72) * 3600;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours.pca', 72));
    }

    public function handle(
        PncpClient $client,
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        SyncPcaPlanAction $upsertAction,
        OrgaoRepository $orgaos,
        PcaPlanRepository $planos,
        PncpPcaCicloRepository $ciclos
    ): void {

        Log::withContext(['job_id' => $this->job ? $this->job->getJobId() : 'master-pca']);

        $startedAt = now();

        if ($failureHandler->shouldWait($this, 'Job Mestre PCA', PncpCircuitBreaker::SCOPE_PCA))
        {
            return;
        }

        try
        {
            // `/consolidado` é um resumo do órgão (objeto único). A lista de
            // planos (sequencial + unidade) vem de `/consolidado/unidades`.
            $pcaUnidadesOrgaos = $client->getPcaUnidades($this->dto->cnpj, $this->dto->ano);

            $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_PCA);

            if ($pcaUnidadesOrgaos === [])
            {
                // Órgão sem nenhum PCA no ano (404 do PNCP): lembra disso para
                // o SyncPcaCommand não gastar uma requisição por rodada nele.
                $orgaos->markPcaVerified($this->dto->cnpj, $this->dto->ano, false);

                // Log::info('PNCP_PCA_FINISHED_EMPTY', [
                //     'cnpj' => $this->dto->cnpj,
                //     'ano'  => $this->dto->ano,
                // ]);
                return;
            }

            $this->syncId = (string) Str::uuid();
            $ciclos->createOrgaoStarted($this->syncId, $this->dto->cnpj, $this->dto->ano, $startedAt);

            $plans = [];
            foreach ($pcaUnidadesOrgaos as $pca)
            {
                if (! is_object($pca))
                {
                    continue;
                }

                $sequencial = (int) ($pca->sequencialPca ?? 0);
                if ($sequencial <= 0)
                {
                    continue;
                }

                $plans[] = new PcaPlanData(
                    cnpjOrgao:          $this->dto->cnpj,
                    anoPca:             $this->dto->ano,
                    sequencialPca:      $sequencial,
                    codigoUnidade:      $pca->codigoUnidade ?? $pca->unidadeOrgao->codigoUnidade ?? null,
                    nomeUnidade:        $pca->nomeUnidade ?? $pca->unidadeOrgao->nomeUnidade ?? null,
                    situacaoPca:        $pca->situacaoPca ?? null,
                    dataPublicacaoPncp: $pca->dataPublicacaoPncp ?? null,
                    dataInclusao:       $pca->dataInclusao ?? null,
                    dataAtualizacao:    $pca->dataAtualizacao ?? null,
                    payloadCompleto:    $pca
                );
            }

            if ($plans === [])
            {
                $orgaos->markPcaVerified($this->dto->cnpj, $this->dto->ano, false);

                $ciclos->markEmpty($this->syncId);

                // Log::info('PNCP_PCA_FINISHED_EMPTY', [
                //     'cnpj'   => $this->dto->cnpj,
                //     'ano'    => $this->dto->ano,
                //     'reason' => 'unidades sem sequencialPca',
                // ]);

                return;
            }

            $orgaos->markPcaVerified($this->dto->cnpj, $this->dto->ano, true);

            // Marca d'água lida ANTES do upsert por clareza: o upsert não
            // toca nessas colunas, mas a decisão é sobre o estado anterior.
            $marcas = $planos->marcasPorSequencial($this->dto->cnpj, $this->dto->ano);

            // Planos cujas páginas ainda podem estar na fila (ciclo started
            // dentro do prazo). Despachar de novo duplicaria o download.
            $emAndamento = $ciclos->sequenciaisEmAndamento($this->dto->cnpj, $this->dto->ano);

            $upsertAction->handle($plans);

            $refreshDays = (int) config('pncp.pca.refresh_after_days', 30);

            foreach ($plans as $plan)
            {
                $pendente = PcaItemsFreshness::isPending(
                    $marcas->get($plan->sequencialPca)?->itens_sincronizados_em,
                    PcaItemsFreshness::latestUpdate(
                        $plan->dataAtualizacao,
                        $plan->payloadCompleto->dataAtualizacaoGlobalPCA ?? null,
                    ),
                    $refreshDays,
                );

                // Itens em dia (marca d'água) ou já a caminho: nada a baixar.
                if (! $pendente || $emAndamento->has($plan->sequencialPca))
                {
                    continue;
                }

                SyncPcaItemsJob::dispatch(
                    $this->dto->cnpj,
                    $this->dto->ano,
                    $plan->sequencialPca
                );
            }

            // O mestre só enumera os planos e despacha um sub-mestre por plano.
            // A contagem de itens/páginas vive no nível do plano; aqui o ciclo
            // de órgão se encerra assim que o fan-out é concluído.
            $ciclos->markFinished($this->syncId);

        }
        catch (\Throwable $error)
        {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action'  => 'SyncPcaDataJob',
                'attempt' => $this->attempts(),
                'cnpj'    => $this->dto->cnpj,
                'ano'     => $this->dto->ano,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($exception instanceof MaxAttemptsExceededException)
        {
            Log::error('PNCP_PCA_ABANDONED', [
                'cnpj'      => $this->dto->cnpj,
                'ano'       => $this->dto->ano,
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
                'reason'    => 'Job Mestre do PCA esgotou a janela de retentativa antes de concluir a sincronização deste órgão. O próximo ciclo agendado tenta de novo.',
            ]);
        }

        if ($this->syncId === '')
        {
            return;
        }

        app(PncpPcaCicloRepository::class)->abandonIfStarted($this->syncId);
    }
}
