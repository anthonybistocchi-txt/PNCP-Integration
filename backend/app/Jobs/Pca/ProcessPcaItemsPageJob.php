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
                ]);
                return; // Ou lançar exceção para retry se o mestre ainda não finalizou commit
            }

            $itemsData = [];
            foreach ($response->data as $item) {
                $itemsData[] = new PcaItemData(
                    pcaPlanId: $pcaPlan->id,
                    numeroItem: $item->numeroItem ?? 0,
                    categoriaItemPca: $item->categoriaItemPca ?? null,
                    categoriaItemPcaNome: $item->categoriaItemPcaNome ?? null,
                    catalogoId: $item->catalogoId ?? null,
                    classificacaoCatalogoId: $item->classificacaoCatalogoId ?? null,
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

            $cycleTracker->markPageDone($this->syncId, count($itemsData));

            if ($cycleTracker->isFinished($this->syncId)) {
                PncpPcaCiclo::where('sync_id', $this->syncId)->update([
                    'status' => 'finished',
                    'finished_at' => now(),
                    'paginas_completas' => $this->pagina,
                ]);
            }

        } catch (\Throwable $error) {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action' => 'ProcessPcaItemsPageJob',
                'attempt' => $this->attempts(),
            ]);
        }
    }
}
