<?php

namespace App\Jobs\Pca;

use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Models\Pca\PncpPcaCiclo;
use App\Support\ErrorHandling\PncpFailureHandler;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncPcaItemsJob implements ShouldQueue
{
    public $timeout = 300;
    public $backoff = [120, 300, 600];

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $syncId = '';

    public function __construct(
        protected string $cnpj,
        protected int $ano,
        protected int $sequencial
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours.listagem', 12));
    }

    public function handle(
        PncpClient $client,
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        PncpSyncCycleTracker $cycleTracker
    ): void {
        Log::withContext(['job_id' => $this->job ? $this->job->getJobId() : 'sub-master-pca']);

        $startedAt = now();

        if ($failureHandler->shouldWait($this, 'Job Sub-Mestre PCA', PncpCircuitBreaker::SCOPE_PCA)) {
            return;
        }

        $tamanhoPagina = 50;

        try {
            $totalRecords = $client->getPcaItensQuantidade($this->cnpj, $this->ano, $this->sequencial);

            $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_PCA);

            if ($totalRecords <= 0) {
                return;
            }

            $totalPaginas = (int) ceil($totalRecords / $tamanhoPagina);
            $this->syncId = (string) Str::uuid();

            $cycleTracker->start($this->syncId, $totalPaginas, $totalRecords);
            PncpPcaCiclo::create([
                'sync_id' => $this->syncId,
                'cnpj_orgao' => $this->cnpj,
                'ano_pca' => $this->ano,
                'sequencial_pca' => $this->sequencial,
                'total_anunciado' => $totalRecords,
                'total_paginas' => $totalPaginas,
                'status' => 'started',
                'started_at' => $startedAt,
            ]);

            for ($pagina = 1; $pagina <= $totalPaginas; $pagina++) {
                ProcessPcaItemsPageJob::dispatch(
                    $this->cnpj,
                    $this->ano,
                    $this->sequencial,
                    $pagina,
                    $tamanhoPagina,
                    $this->syncId
                );
            }
        } catch (\Throwable $error) {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action' => 'SyncPcaItemsJob',
                'attempt' => $this->attempts(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->syncId === '') {
            return;
        }

        PncpPcaCiclo::where('sync_id', $this->syncId)->update([
            'status' => 'abandoned',
            'finished_at' => now(),
        ]);
    }
}
