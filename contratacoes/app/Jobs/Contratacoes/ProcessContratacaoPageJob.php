<?php

namespace App\Jobs\Contratacoes;

use App\Actions\Contratacoes\SyncContratacaoAction;
use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpDocumentsDispatchSequencer;
use App\Integrations\PNCP\CicloContratacaoRecorder;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Models\Contratacoes\CicloContratacao;
use App\Support\ErrorHandling\PncpFailureHandler;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessContratacaoPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Fase 1 faz apenas 1 requisição HTTP por execução (contratacoes da página).
    // 120 segundos cobre o timeout máximo de 40s + overhead de fila + DB.
    public $timeout = 120;

    // Atrasos para falhas que não são da API do PNCP (deadlock de banco, por
    // exemplo). Falha de API vira release com a pausa do circuit breaker.
    public $backoff = [120, 300, 600, 1200, 2400];

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ListContratacoesDTO $dto,
        protected int $pagina,
        protected int $tamanhoPagina,
        protected string $syncId = '',
        protected string $cycleStartedAt = ''
    ) {}

    /**
     * Limite por tempo em vez de por tentativas: release() incrementa o
     * contador de tentativas, então esperar o circuit breaker algumas vezes
     * mataria o job por MaxAttemptsExceeded sem ele ter falhado.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours.contratacoes', 12));
    }

    /**
     * Execute the job.
     */
    public function handle(
        PncpClient $client,
        SyncContratacaoAction $syncAction,
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        PncpSyncCycleTracker $cycleTracker,
        PncpDocumentsDispatchSequencer $documentsSequencer,
        CicloContratacaoRecorder $cicloRecorder
    ): void {
        Log::withContext([
            'sync_id' => $this->syncId,
            'job_id'  => $this->job ? $this->job->getJobId() : 'sync',
            'page'    => $this->pagina,
        ]);

        if ($failureHandler->shouldWait($this, "Job da página {$this->pagina}", PncpCircuitBreaker::SCOPE_CONTRATACOES)) {
            return;
        }

        $queryParams = array_filter([
            'dataInicial'                 => $this->dto->dataInicial,
            'dataFinal'                   => $this->dto->dataFinal,
            'codigoModalidadeContratacao' => $this->dto->codigoModalidadeContratacao,
            'uf'                          => $this->dto->uf,
            'codigoMunicipioIbge'         => $this->dto->codigoMunicipioIbge,
            'pagina'                      => $this->pagina,
            'tamanhoPagina'               => $this->tamanhoPagina,
        ]);

        try {
            $response = $client->getContratacoes($queryParams);

            // A API respondeu: qualquer pausa em curso pode ser encerrada.
            $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_CONTRATACOES);

            if (! $response || empty($response->data)) {
                $this->markCyclePageDone($cycleTracker, $documentsSequencer, $cicloRecorder, 0);

                return;
            }

            // Fase 1: persiste os dados da licitação com documentos = [].
            // A Fase 2 (SyncDocumentosJob) preencherá os documentos de forma
            // desacoplada, após o ciclo completo de todas as páginas.
            $syncAction->handle($response->data);

            $this->markCyclePageDone($cycleTracker, $documentsSequencer, $cicloRecorder, count($response->data));
        } catch (\Throwable $error) {
            // Reagendado (API indisponível ou em pausa): a página ainda vai
            // rodar, então o contador do ciclo não pode avançar.
            if ($failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_CONTRATACOES, [
                'action'      => 'ProcessContratacaoPageJob',
                'page'        => $this->pagina,
                'page_size'   => $this->tamanhoPagina,
                'attempt'     => $this->attempts(),
                'modalidade'  => $this->dto->codigoModalidadeContratacao,
            ])) {
                return;
            }

            // Só chega aqui quando a falha é permanente/crítica (não haverá retry).
            $this->markCyclePageDone($cycleTracker, $documentsSequencer, $cicloRecorder, 0);
        }
    }

    /**
     * Incrementa o contador do ciclo. A última página emite PNCP_CONTRATACOES_FINISHED
     * e dispara automaticamente a Fase 2 (SyncDocumentosJob), com um delay
     * escalonado para não empilhar o início da Fase 2 de várias modalidades
     * que terminam a contratacoes quase juntas.
     * Release (circuit breaker) e retry transiente não devem chamar este método.
     *
     * @return array{units_completed: int, items_processed: int, total_records: int}|null
     */
    private function markCyclePageDone(
        PncpSyncCycleTracker $cycleTracker,
        PncpDocumentsDispatchSequencer $documentsSequencer,
        CicloContratacaoRecorder $cicloRecorder,
        int $itemsProcessed
    ): ?array {
        $summary = $cycleTracker->markPageDone($this->syncId, $itemsProcessed);

        if ($summary === null) {
            return null;
        }

        $finishedAt   = now();
        $startedAt    = $this->cycleStartedAt !== '' ? Carbon::parse($this->cycleStartedAt) : null;
        $delayMinutes = $documentsSequencer->nextDelayMinutes();

        $cicloRecorder->finish(
            $this->syncId,
            $summary['items_processed'],
            $summary['units_completed'],
            CicloContratacao::STATUS_FINISHED
        );

        Log::info('PNCP_CONTRATACOES_FINISHED', [
            'sync_id'          => $this->syncId,
            'modalidade'       => $this->dto->codigoModalidadeContratacao,
            'dataInicial'      => $this->dto->dataInicial,
            'dataFinal'        => $this->dto->dataFinal,
            'status'           => 'ok',
            'total_records'    => $summary['total_records'],
            'items_processed'  => $summary['items_processed'],
            'pages_completed'  => $summary['units_completed'],
            'duration_seconds' => $startedAt ? $startedAt->diffInSeconds($finishedAt) : null,
            'docs_delay_min'   => $delayMinutes,
            'finished_at'      => $finishedAt->toIso8601String(),
        ]);

        // Dispara a Fase 2: busca os documentos de todas as licitações
        // sincronizadas neste ciclo. O job mestre filtra por documentos_sincronizados_em
        // IS NULL entre os registros que este ciclo tocou (updated_at >=
        // cycleStartedAt), garantindo idempotência.
        //
        // O delay escalonado evita que modalidades que terminam a contratacoes
        // quase juntas (o caso comum) bombardeiem /arquivos ao mesmo tempo.
        SyncDocumentosJob::dispatch($this->dto, $this->cycleStartedAt)
            ->delay(now()->addMinutes($delayMinutes));

        return $summary;
    }

    /**
     * Página descartada (retryUntil). Conta o lote no ciclo Redis com 0 itens
     * (mesmo padrão dos documentos). Se essa morte fechar o ciclo, o finish
     * já foi gravado; senão marca abandoned para o gap ficar queryável.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PNCP_CONTRATACOES_PAGE_ABANDONED', [
            'sync_id'    => $this->syncId,
            'modalidade' => $this->dto->codigoModalidadeContratacao,
            'page'       => $this->pagina,
            'exception'  => get_class($exception),
            'message'    => $exception->getMessage(),
            'reason'     => 'Página de contratacoes esgotou o retryUntil. Se o ciclo não fechar pelas demais páginas, o intervalo fica incompleto em ciclos_contratacao.',
        ]);

        $summary = $this->markCyclePageDone(
            app(PncpSyncCycleTracker::class),
            app(PncpDocumentsDispatchSequencer::class),
            app(CicloContratacaoRecorder::class),
            0
        );

        if ($summary === null) {
            app(CicloContratacaoRecorder::class)->abandon(
                $this->syncId,
                'ProcessContratacaoPageJob esgotou o retryUntil na página ' . $this->pagina
            );
        }
    }
}
