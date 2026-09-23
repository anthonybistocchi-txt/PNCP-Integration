<?php

namespace App\Jobs\Pca;

use App\Actions\Pca\SyncPcaItemsAction;
use App\DTOs\Pca\PcaItemData;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Models\Pca\PcaPlan;
use App\Models\Pca\PncpPcaCiclo;
use App\Support\ErrorHandling\PncpFailureHandler;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPcaItemsPageJob implements ShouldQueue
{
    public $timeout = 300;
    public $backoff = [60, 180, 300];

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $cnpj,
        protected int $ano,
        protected int $sequencial,
        protected int $pagina,
        protected int $tamanhoPagina,
        protected string $syncId
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours.listagem', 12));
    }

    public function handle(
        PncpClient $client,
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        PncpSyncCycleTracker $cycleTracker,
        SyncPcaItemsAction $upsertAction
    ): void {
        if ($failureHandler->shouldWait($this, 'Job Page PCA', PncpCircuitBreaker::SCOPE_PCA)) {
            return;
        }

        try {
            $response = $client->getPcaItens($this->cnpj, $this->ano, $this->sequencial, [
                'pagina' => $this->pagina,
                'tamanhoPagina' => $this->tamanhoPagina,
            ]);

            $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_PCA);

            if (! $response || empty($response->data)) {
                $this->markCyclePageDone($cycleTracker, 0);

                return;
            }

            // Precisamos do ID do pca_plan para inserir
            $pcaPlan = PcaPlan::where('cnpj_orgao', $this->cnpj)
                ->where('ano_pca', $this->ano)
                ->where('sequencial_pca', $this->sequencial)
                ->first();

            if (! $pcaPlan) {
                Log::warning('PCA_PLAN_NOT_FOUND', [
                    'cnpj' => $this->cnpj,
                    'ano' => $this->ano,
                    'sequencial' => $this->sequencial,
                    'page' => $this->pagina,
                ]);
                // Plano ainda não commitado pelo mestre: reagenda sem avançar o ciclo.
                $this->release(30);

                return;
            }

            $itemsData = [];
            foreach ($response->data as $item) {
                $itemsData[] = new PcaItemData(
                    pcaPlanId: (int) $pcaPlan->id,
                    numeroItem: (int) ($item->numeroItem ?? 0),
                    categoriaItemPca: isset($item->categoriaItemPca) ? (int) $item->categoriaItemPca : null,
                    categoriaItemPcaNome: $item->categoriaItemPcaNome ?? null,
                    catalogoId: isset($item->catalogoId) ? (int) $item->catalogoId : null,
                    classificacaoCatalogoId: isset($item->classificacaoCatalogoId) ? (int) $item->classificacaoCatalogoId : null,
                    classificacaoSuperiorCodigo: $item->classificacaoSuperiorCodigo ?? null,
                    classificacaoSuperiorNome: $item->classificacaoSuperiorNome ?? null,
                    pdmCodigo: $item->pdmCodigo ?? null,
                    pdmNome: $item->pdmNome ?? null,
                    codigoItem: $item->codigoItem ?? null,
                    descricaoItem: $item->descricaoItem ?? null,
                    unidadeFornecimento: $item->unidadeFornecimento ?? null,
                    quantidade: isset($item->quantidade) ? (float) $item->quantidade : null,
                    valorUnitario: isset($item->valorUnitario) ? (float) $item->valorUnitario : null,
                    valorTotal: isset($item->valorTotal) ? (float) $item->valorTotal : null,
                    valorOrcamentoExercicio: isset($item->valorOrcamentoExercicio) ? (float) $item->valorOrcamentoExercicio : null,
                    dataDesejada: $item->dataDesejada ?? null,
                    unidadeRequisitante: $item->unidadeRequisitante ?? null,
                    codigoUnidadeRequisitante: $item->codigoUnidadeRequisitante ?? null,
                    grupoContratacaoCodigo: $item->grupoContratacaoCodigo ?? null,
                    grupoContratacaoNome: $item->grupoContratacaoNome ?? null,
                    dataPublicacaoPncp: $item->dataPublicacaoPncp ?? null,
                    dataInclusao: $item->dataInclusao ?? null,
                    dataAtualizacao: $item->dataAtualizacao ?? null
                );
            }

            $upsertAction->handle($itemsData);

            $this->markCyclePageDone($cycleTracker, count($itemsData));
        } catch (\Throwable $error) {
            // Reagendado (API indisponível ou em pausa): a página ainda vai
            // rodar, então o contador do ciclo não pode avançar.
            if ($failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action' => 'ProcessPcaItemsPageJob',
                'attempt' => $this->attempts(),
                'cnpj' => $this->cnpj,
                'ano' => $this->ano,
                'sequencial' => $this->sequencial,
                'page' => $this->pagina,
            ])) {
                return;
            }

            // Falha permanente: conta a página no ciclo com 0 itens.
            $this->markCyclePageDone($cycleTracker, 0);
        }
    }

    /**
     * Incrementa o contador do ciclo. markPageDone devolve o resumo só na
     * última unidade — não existe isFinished() no tracker.
     *
     * @return array{units_completed: int, items_processed: int, total_records: int}|null
     */
    private function markCyclePageDone(PncpSyncCycleTracker $cycleTracker, int $itemsProcessed): ?array
    {
        $summary = $cycleTracker->markPageDone($this->syncId, $itemsProcessed);

        if ($summary === null) {
            return null;
        }

        PncpPcaCiclo::where('sync_id', $this->syncId)->update([
            'status' => 'finished',
            'finished_at' => now(),
            'paginas_completas' => $summary['units_completed'],
            'items_processados' => $summary['items_processed'],
        ]);

        return $summary;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PNCP_PCA_PAGE_ABANDONED', [
            'sync_id' => $this->syncId,
            'cnpj' => $this->cnpj,
            'ano' => $this->ano,
            'sequencial' => $this->sequencial,
            'page' => $this->pagina,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);

        $summary = $this->markCyclePageDone(app(PncpSyncCycleTracker::class), 0);

        if ($summary === null) {
            PncpPcaCiclo::where('sync_id', $this->syncId)->update([
                'status' => 'abandoned',
                'finished_at' => now(),
            ]);
        }
    }
}
