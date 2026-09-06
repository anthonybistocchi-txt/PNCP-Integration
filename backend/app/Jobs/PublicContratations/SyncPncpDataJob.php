<?php

namespace App\Jobs\PublicContratations;

use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Support\ErrorHandling\PncpFailureHandler;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncPncpDataJob implements ShouldQueue, ShouldBeUnique
{
    // Dá 5 minutos (300 segundos) de tolerância antes de matar o Job
    public $timeout = 300;

    // Atrasos para retentativas de falhas que não são da API do PNCP
    // (deadlock de banco, por exemplo). Falha de API não passa por aqui:
    // vira release com a pausa que o circuit breaker determinar.
    public $backoff = [120, 300, 600];

    // Precisa cobrir a janela do retryUntil, senão o lock de unicidade expira
    // enquanto o job ainda está esperando o circuit breaker.
    public int $uniqueFor = 21600; // 6 horas

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ListPublicContratationsDTO $dto
    ) {}

    /**
     * Unique por modalidade + intervalo. Sem isso, 10 modalidades no mesmo
     * comando colapsam num único job (lock padrão é o nome da classe).
     */
    public function uniqueId(): string
    {
        return implode(':', [
            $this->dto->codigoModalidadeContratacao,
            $this->dto->dataInicial,
            $this->dto->dataFinal,
        ]);
    }

    /**
     * Limite por tempo em vez de por tentativas.
     *
     * release() incrementa o contador de tentativas no Laravel, então um job
     * que apenas espera o circuit breaker morreria de MaxAttemptsExceeded sem
     * ter falhado por conta própria. Com retryUntil definido o $tries deixa de
     * valer e quem manda é o relógio.
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
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        PncpSyncCycleTracker $cycleTracker
    ): void {
        Log::withContext([
            'job_id' => $this->job ? $this->job->getJobId() : 'master',
        ]);

        $startedAt = now();

        if ($failureHandler->shouldWait($this, 'Job Mestre', PncpCircuitBreaker::SCOPE_LISTAGEM)) {
            return;
        }

        Log::info('SyncPncpDataJob (MASTER) started.', [
            'modalidade'  => $this->dto->codigoModalidadeContratacao,
            'dataInicial' => $this->dto->dataInicial,
            'dataFinal'   => $this->dto->dataFinal,
        ]);

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

        try {
            $response = $client->getContratacoes($queryParams);

            // A API respondeu: qualquer pausa em curso pode ser encerrada.
            $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_LISTAGEM);

            if (! $response || empty($response->data)) {
                Log::info('SyncPncpDataJob (MASTER) finished. No records found to sync.');

                return;
            }

            $totalPaginas = $response->totalPaginas ?? 1;
            Log::info("PNCP API reports {$response->totalRegistros} total records across {$totalPaginas} pages.");

            $syncId = (string) Str::uuid();

            $cycleTracker->start($syncId, $totalPaginas);

            $staggerSeconds = max(0, (int) config('pncp.page_stagger_seconds', 0));

            for ($pagina = 1; $pagina <= $totalPaginas; $pagina++) {
                $job = ProcessPncpPageJob::dispatch(
                    $this->dto,
                    $pagina,
                    $tamanhoPagina,
                    $syncId,
                    $startedAt->toIso8601String()
                );

                if ($staggerSeconds > 0) {
                    $job->delay(now()->addSeconds(($pagina - 1) * $staggerSeconds));
                }
            }

            Log::info('SYNC_PAGES_DISPATCHED', [
                'sync_id'                => $syncId,
                'modalidade'             => $this->dto->codigoModalidadeContratacao,
                'dataInicial'            => $this->dto->dataInicial,
                'dataFinal'              => $this->dto->dataFinal,
                'total_pages_dispatched' => $totalPaginas,
                'total_records_reported' => $response->totalRegistros,
                'started_at'             => $startedAt->toIso8601String(),
            ]);
        } catch (\Throwable $error) {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_LISTAGEM, [
                'action'      => 'SyncPncpDataJob',
                'attempt'     => $this->attempts(),
                'modalidade'  => $this->dto->codigoModalidadeContratacao,
                'dataInicial' => $this->dto->dataInicial,
                'dataFinal'   => $this->dto->dataFinal,
            ]);
        }
    }
}
