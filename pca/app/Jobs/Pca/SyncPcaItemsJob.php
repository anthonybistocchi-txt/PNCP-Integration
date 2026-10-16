<?php

namespace App\Jobs\Pca;

use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Models\Pca\PncpPcaCiclo;
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

    /**
     * Gerado no construtor para viajar no payload: o failed() roda numa
     * instância desserializada, que nunca viu o que o handle() atribuiu. Com o
     * id gerado só no handle(), um job que morria por prazo não achava o
     * próprio ciclo para abandonar e deixava um `started` órfão.
     *
     * O default não chega num payload antigo. O __unserialize do Laravel pula
     * a chave ausente e a propriedade tipada fica não inicializada; lê-la
     * lança "must not be accessed before initialization".
     */
    protected string $syncId = '';

    public function __construct(
        protected string $cnpj,
        protected int $ano,
        protected int $sequencial
    ) {
        $this->syncId = (string) Str::uuid();
    }

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

        // Payload anterior ao id no construtor: chave ausente (não inicializada)
        // ou ''. Sem um id próprio, esses jobs dividiriam o mesmo sync_id.
        if (! isset($this->syncId) || $this->syncId === '')
        {
            $this->syncId = (string) Str::uuid();
        }

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
            // Nova tentativa de um job que já tinha aberto o ciclo (a falha foi
            // ao despachar as páginas): retoma o mesmo ciclo, sem remedir e sem
            // zerar o progresso das páginas que já terminaram. Despachar de novo
            // uma página já feita é inofensivo: o upsert é idempotente e o
            // tracker conta cada página uma só vez.
            $existente = $ciclos->findBySyncId($this->syncId);

            if ($existente !== null && $existente->status === PncpPcaCiclo::STATUS_STARTED && $existente->total_paginas > 0)
            {
                if (! $cycleTracker->isStarted($this->syncId))
                {
                    $cycleTracker->start($this->syncId, $existente->total_paginas, $existente->total_anunciado);
                }

                $this->dispatchPages($existente->total_paginas, $tamanhoPagina);

                return;
            }

            $totalRecords = $client->getPcaItensQuantidade($this->cnpj, $this->ano, $this->sequencial);

            $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_PCA);

            if ($totalRecords === null)
            {
                // 404: o plano consta na listagem do órgão, então não existir
                // aqui é anomalia do PNCP, não "plano sem itens" (que responde
                // 200 com 0). Sem ciclo e sem marca d'água: o plano continua
                // pendente e o sync-pca-faltantes tenta de novo, em vez de
                // esconder os itens por até `refresh_after_days`.
                Log::warning('PNCP_PCA_ITENS_QUANTIDADE_NOT_FOUND', [
                    'cnpj'       => $this->cnpj,
                    'ano'        => $this->ano,
                    'sequencial' => $this->sequencial,
                ]);

                return;
            }

            if ($totalRecords <= 0)
            {
                $ciclos->createPlanEmpty($this->cnpj, $this->ano, $this->sequencial, $startedAt, $this->syncId);

                // Plano sem itens também está "em dia": sem a marca ele seria
                // pendente para sempre e reconsultado a cada rodada.
                $planos->markItensSincronizados($this->cnpj, $this->ano, $this->sequencial, $startedAt, 0);

                return;
            }

            $totalPaginas = (int) ceil($totalRecords / $tamanhoPagina);

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

            $this->dispatchPages($totalPaginas, $tamanhoPagina);
        }
        catch (\Throwable $error)
        {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action'  => 'SyncPcaItemsJob',
                'attempt' => $this->attempts(),
            ]);
        }
    }

    private function dispatchPages(int $totalPaginas, int $tamanhoPagina): void
    {
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

    public function failed(\Throwable $exception): void
    {
        $ciclos = app(PncpPcaCicloRepository::class);

        // Job antigo (serializado antes do id no construtor): a propriedade
        // não veio no payload. Não há ciclo a localizar, só o registro do gap.
        if (! isset($this->syncId) || $this->syncId === '')
        {
            $ciclos->createPlanAbandoned($this->cnpj, $this->ano, $this->sequencial);

            return;
        }

        // Ciclo aberto e não concluído: abandona.
        $ciclos->abandonIfStarted($this->syncId);

        // Falhou antes de medir a quantidade (ex.: API fora do ar durante todo
        // o retryUntil): o ciclo de plano nem chegou a ser criado. Registra um
        // registro abandonado para o gap ficar visível em ciclos_pca.
        if ($ciclos->findBySyncId($this->syncId) === null)
        {
            $ciclos->createPlanAbandoned($this->cnpj, $this->ano, $this->sequencial, $this->syncId);
        }
    }
}
