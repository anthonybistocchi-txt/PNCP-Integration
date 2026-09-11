<?php

namespace App\Jobs\PublicContratations;

use App\Actions\PublicContratations\SyncPublicContratationDocumentsAction;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Support\ErrorHandling\PncpFailureHandler;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Worker da Fase 2: recebe um lote de licitações e busca os documentos
 * (editais, anexos) de cada uma, persistindo o resultado de forma atômica.
 *
 * Compartilha o mesmo PncpCircuitBreaker e PncpFailureHandler da Fase 1,
 * garantindo que instabilidade do PNCP interrompa apenas os documentos,
 * sem impactar o ciclo de listagem do dia seguinte.
 *
 * Idempotência: o upsert parcial (apenas documentos + documents_fetched_at)
 * garante que reprocessamentos não corrompam os dados da Fase 1.
 */
class ProcessPncpDocumentPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 50 itens × timeout de 10s por chamada = 500s máximo teórico.
    // 900s dá folga para jitter, retry HTTP interno e escrita no DB.
    public $timeout = 900;

    // Atrasos para falhas que não são da API do PNCP (deadlock de banco, etc.).
    // Falha de API vira release com a pausa do circuit breaker.
    public $backoff = [120, 300, 600, 1200, 2400];

    /**
     * Create a new job instance.
     *
     * @param array<array{
     *     numero_controle_pncp: string,
     *     orgao_cnpj: string,
     *     ano_compra: int,
     *     sequencial_compra: int
     * }> $items Lote de licitações para buscar documentos.
     */
    public function __construct(
        protected array $items,
        protected string $syncId = '',
        protected int $modalidade = 0,
        protected string $cycleStartedAt = ''
    ) {}

    /**
     * Limite por tempo em vez de por tentativas.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours', 6));
    }

    /**
     * Execute the job.
     */
    public function handle(
        PncpClient $client,
        SyncPublicContratationDocumentsAction $syncAction,
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        PncpSyncCycleTracker $cycleTracker
    ): void {
        Log::withContext([
            'sync_id'    => $this->syncId,
            'job_id'     => $this->job ? $this->job->getJobId() : 'documents',
            'modalidade' => $this->modalidade,
            'lote_size'  => count($this->items),
        ]);

        if ($failureHandler->shouldWait($this, 'Job de Documentos', PncpCircuitBreaker::SCOPE_DOCUMENTOS)) {
            return;
        }

        $upsertData = [];

        try {
            foreach ($this->items as $item) {
                $cnpj       = $item['orgao_cnpj'];
                $ano        = $item['ano_compra'];
                $sequencial = $item['sequencial_compra'];

                $documentos = $client->getArquivos($cnpj, $ano, $sequencial);

                // Registra sucesso parcial para manter o circuit breaker atualizado.
                // Em caso de falha abaixo do limiar, o breaker não abre ainda.
                $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_DOCUMENTOS);

                $upsertData[] = [
                    'numero_controle_pncp' => $item['numero_controle_pncp'],
                    'documentos'           => json_encode($documentos),
                    'documents_fetched_at' => now()->toDateTimeString(),
                ];
            }

            // Persiste o lote inteiro de uma vez (1 query para N itens).
            // Se uma exceção ocorreu no loop acima, o upsert não é chamado e o
            // job inteiro é devolvido para a fila — garantindo atomicidade do lote.
            $syncAction->handle($upsertData);

            $this->markCyclePageDone($cycleTracker, count($upsertData));
        } catch (\Throwable $error) {
            // Reagendado (API indisponível ou em pausa): o lote ainda vai rodar,
            // então o contador do ciclo de documentos não pode avançar.
            if ($failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_DOCUMENTOS, [
                'action'    => 'ProcessPncpDocumentPageJob',
                'lote_size' => count($this->items),
                'attempt'   => $this->attempts(),
                'modalidade'=> $this->modalidade,
            ])) {
                return;
            }

            // Só chega aqui quando a falha é permanente/crítica (não haverá retry).
            $this->markCyclePageDone($cycleTracker, 0);
        }
    }

    /**
     * Incrementa o contador do ciclo de documentos.
     * A última página emite PNCP_DOCUMENTOS_FINISHED.
     */
    private function markCyclePageDone(PncpSyncCycleTracker $cycleTracker, int $itemsProcessed): void
    {
        $summary = $cycleTracker->markPageDone($this->syncId, $itemsProcessed);

        if ($summary === null) {
            return;
        }

        // Libera a trava de modalidade (ver PncpSyncCycleTracker::tryStartModalidade)
        // assim que o ciclo termina, para que o próximo ciclo agendado (Fase 2
        // do dia seguinte ou a próxima varredura de pendentes) já possa rodar.
        $cycleTracker->finishModalidade($this->modalidade, $this->syncId);

        $finishedAt = now();
        $startedAt  = $this->cycleStartedAt !== '' ? Carbon::parse($this->cycleStartedAt) : null;

        Log::info('PNCP_DOCUMENTOS_FINISHED', [
            'sync_id'          => $this->syncId,
            'modalidade'       => $this->modalidade,
            'status'           => 'ok',
            'total_records'    => $summary['total_records'],
            'items_processed'  => $summary['items_processed'],
            'lotes_completed'  => $summary['units_completed'],
            'duration_seconds' => $startedAt ? $startedAt->diffInSeconds($finishedAt) : null,
            'finished_at'      => $finishedAt->toIso8601String(),
        ]);
    }

    /**
     * Chamado pelo Laravel quando o job é descartado permanentemente (ex.:
     * retryUntil expirou durante uma degradação prolongada do PNCP). Sem
     * isso, o abandono de um lote de documentos some no log genérico de
     * MaxAttemptsExceeded, sem contexto de modalidade/lote nenhum.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PNCP_DOCUMENTS_PHASE_ABANDONED', [
            'sync_id'    => $this->syncId,
            'modalidade' => $this->modalidade,
            'lote_size'  => count($this->items),
            'exception'  => get_class($exception),
            'message'    => $exception->getMessage(),
            'reason'     => 'Lote de documentos esgotou o retryUntil sem conseguir buscar os arquivos. Os registros continuam com documents_fetched_at nulo e serão retentados no próximo ciclo diário.',
        ]);
    }
}
