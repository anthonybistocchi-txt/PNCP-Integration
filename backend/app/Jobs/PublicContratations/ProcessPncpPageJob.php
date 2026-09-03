<?php

namespace App\Jobs\PublicContratations;

use App\Actions\PublicContratations\SyncPublicContratationAction;
use App\DTOs\PublicContratations\ListPublicContratationsDTO;
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

class ProcessPncpPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // No pior caso são 51 requisições por página passando pelo limitador de
    // taxa global. Na prática a espera é sub-segundo, mas o teto precisa de
    // folga. Manter REDIS_QUEUE_RETRY_AFTER acima deste valor.
    public $timeout = 900;

    // Atrasos para falhas que não são da API do PNCP (deadlock de banco, por
    // exemplo). Falha de API vira release com a pausa do circuit breaker.
    public $backoff = [120, 300, 600, 1200, 2400];

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ListPublicContratationsDTO $dto,
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
        return now()->addHours((int) config('pncp.retry_until_hours', 6));
    }

    /**
     * Execute the job.
     */
    public function handle(
        PncpClient $client,
        SyncPublicContratationAction $syncAction,
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        PncpSyncCycleTracker $cycleTracker
    ): void {
        Log::withContext([
            'sync_id' => $this->syncId,
            'job_id'  => $this->job ? $this->job->getJobId() : 'sync',
            'page'    => $this->pagina,
        ]);

        if ($failureHandler->shouldWait($this, "Job da página {$this->pagina}")) {
            return;
        }

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

        try {
            $response = $client->getContratacoes($queryParams);

            // A API respondeu: qualquer pausa em curso pode ser encerrada.
            $circuitBreaker->recordSuccess();

            if (! $response || empty($response->data)) {
                Log::info("ProcessPncpPageJob finished. No data on page {$this->pagina}.");
                $this->markCyclePageDone($cycleTracker);

                return;
            }

            // Para cada item da página, vamos buscar os documentos (editais, anexos) associados
            foreach ($response->data as $item) {
                $cnpj       = $item->orgaoEntidade->cnpj ?? null;
                $ano        = $item->anoCompra ?? null;
                $sequencial = $item->sequencialCompra ?? null;

                if ($cnpj && $ano && $sequencial) {
                    $item->documentos = $client->getArquivos($cnpj, $ano, $sequencial);

                    $delayMs = max(0, (int) config('pncp.document_delay_ms', 0));
                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                } else {
                    $item->documentos = [];
                }
            }

            // Passa o array completo para a Action fazer o Bulk Upsert
            $syncAction->handle($response->data);

            Log::info('ProcessPncpPageJob finished. Processed ' . count($response->data) . " items on page {$this->pagina}.");
            $this->markCyclePageDone($cycleTracker);
        } catch (\Throwable $error) {
            // Reagendado (API indisponível ou em pausa): a página ainda vai
            // rodar, então o contador do ciclo não pode avançar.
            if ($failureHandler->handle($error, $this, [
                'action'    => 'ProcessPncpPageJob',
                'page'      => $this->pagina,
                'page_size' => $this->tamanhoPagina,
                'attempt'   => $this->attempts(),
                'dto'       => (array) $this->dto,
            ])) {
                return;
            }

            // Só chega aqui quando a falha é permanente/crítica (não haverá retry).
            $this->markCyclePageDone($cycleTracker);
        }
    }

    /**
     * Incrementa o contador do ciclo. A última página emite SYNC_CYCLE_FINISHED.
     * Release (circuit breaker) e retry transiente não devem chamar este método.
     */
    private function markCyclePageDone(PncpSyncCycleTracker $cycleTracker): void
    {
        $pagesCompleted = $cycleTracker->markPageDone($this->syncId);

        if ($pagesCompleted === null) {
            return;
        }

        $finishedAt = now();
        $startedAt  = $this->cycleStartedAt !== '' ? Carbon::parse($this->cycleStartedAt) : null;

        Log::info('SYNC_CYCLE_FINISHED', [
            'sync_id'          => $this->syncId,
            'modalidade'       => $this->dto->codigoModalidadeContratacao,
            'pages_completed'  => $pagesCompleted,
            'duration_minutes' => $startedAt ? $startedAt->diffInMinutes($finishedAt) : null,
            'started_at'       => $this->cycleStartedAt ?: null,
            'finished_at'      => $finishedAt->toIso8601String(),
            'dto'              => (array) $this->dto,
            'message'          => "Todos os {$pagesCompleted} jobs do ciclo foram finalizados.",
        ]);
    }
}
