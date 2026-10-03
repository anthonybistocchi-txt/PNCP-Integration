<?php

namespace App\Jobs\Pca;

use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Repositories\Pca\PcaPlanRepository;
use App\Repositories\Pca\PncpPcaCicloRepository;
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

class SyncPcaItemsJob implements ShouldQueue, ShouldBeUnique
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

    /**
     * Um sub-mestre por plano. Sem isso, o sync-pca e o sync-pca-faltantes
     * (ou duas execuções do faltantes) enfileirariam o mesmo plano duas vezes
     * e baixariam as páginas em dobro contra o mesmo limitador.
     */
    public function uniqueId(): string
    {
        return "{$this->cnpj}:{$this->ano}:{$this->sequencial}";
    }

    /**
     * O lock cobre a espera na fila (é liberado quando o job termina ou
     * falha), então o teto acompanha o prazo do próprio job.
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
        PncpSyncCycleTracker $cycleTracker,
        PcaPlanRepository $planos,
        PncpPcaCicloRepository $ciclos
    ): void {
        Log::withContext(['job_id' => $this->job ? $this->job->getJobId() : 'sub-master-pca']);

        $startedAt = now();

        if ($failureHandler->shouldWait($this, 'Job Sub-Mestre PCA', PncpCircuitBreaker::SCOPE_PCA))
        {
            return;
        }

        // 500 itens por página: o PNCP devolve conteúdo idêntico ao de 50 com
        // ~5,5x menos requisições no catálogo (ver config/pncp.php).
        $tamanhoPagina = max(1, (int) config('pncp.pca.page_size', 500));

        try
        {
            $totalRecords = $client->getPcaItensQuantidade($this->cnpj, $this->ano, $this->sequencial);

            $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_PCA);

            if ($totalRecords <= 0)
            {
                $ciclos->createPlanEmpty($this->cnpj, $this->ano, $this->sequencial, $startedAt);

                // Plano sem itens também está "em dia": sem a marca ele seria
                // pendente para sempre e reconsultado a cada rodada.
                $planos->markItensSincronizados($this->cnpj, $this->ano, $this->sequencial, $startedAt, 0);

                return;
            }

            $totalPaginas = (int) ceil($totalRecords / $tamanhoPagina);
            $this->syncId = (string) Str::uuid();

            $cycleTracker->start($this->syncId, $totalPaginas, $totalRecords);
            $ciclos->createPlanStarted(
                $this->syncId,
                $this->cnpj,
                $this->ano,
                $this->sequencial,
                $totalRecords,
                $totalPaginas,
                $startedAt
            );

            for ($pagina = 1; $pagina <= $totalPaginas; $pagina++)
            {
                ProcessPcaItemsPageJob::dispatch(
                    $this->cnpj,
                    $this->ano,
                    $this->sequencial,
                    $pagina,
                    $tamanhoPagina,
                    $this->syncId
                );
            }

        }
        catch (\Throwable $error)
        {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action'  => 'SyncPcaItemsJob',
                'attempt' => $this->attempts(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Falhou antes de medir a quantidade (ex.: API fora do ar durante todo
        // o retryUntil): o ciclo de plano nem chegou a ser criado. Registra um
        // registro abandonado para o gap ficar visível em ciclos_pca.
        $ciclos = app(PncpPcaCicloRepository::class);

        if ($this->syncId === '')
        {
            $ciclos->createPlanAbandoned($this->cnpj, $this->ano, $this->sequencial);

            return;
        }

        $ciclos->abandonIfStarted($this->syncId);
    }
}
