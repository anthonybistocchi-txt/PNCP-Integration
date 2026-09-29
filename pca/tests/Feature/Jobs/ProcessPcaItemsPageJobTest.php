<?php

namespace Tests\Feature\Jobs;

use App\Actions\Pca\SyncPcaItemsAction;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Jobs\Pca\ProcessPcaItemsPageJob;
use App\Models\Pca\PcaPlan;
use App\Models\Pca\PncpPcaCiclo;
use App\Support\ErrorHandling\PncpFailureHandler;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ProcessPcaItemsPageJobTest extends TestCase
{
    public function test_persists_items_from_the_array_payload()
    {
        $plan = PcaPlan::create([
            'cnpj_orgao' => '12345678000199',
            'ano_pca' => 2026,
            'sequencial_pca' => 1,
            'data_publicacao_pncp' => now(),
        ]);

        $syncId = (string) Str::uuid();

        PncpPcaCiclo::create([
            'sync_id' => $syncId,
            'cnpj_orgao' => '12345678000199',
            'ano_pca' => 2026,
            'sequencial_pca' => 1,
            'total_anunciado' => 1,
            'total_paginas' => 1,
            'status' => 'started',
            'started_at' => now(),
        ]);

        $tracker = $this->app->make(PncpSyncCycleTracker::class);
        $tracker->start($syncId, 1, 1);

        // Payload no formato real devolvido por /itens (array de objetos).
        $item = (object) [
            'numeroItem' => 1,
            'categoriaItemPcaid' => 1,
            'categoriaItemPcaNome' => 'Material',
            'catalogoId' => 1,
            'classificacaoCatalogoId' => 1,
            'classificacaoSuperiorCodigo' => '7350',
            'classificacaoSuperiorNome' => 'LOUCA E ARTIGOS DE MESA',
            'pdmCodigo' => '5849',
            'pdmDescricao' => 'COPO DESCARTAVEL',
            'codigoItem' => null,
            'descricao' => 'Descricao vinda do campo descricao',
            'unidadeFornecimento' => '-',
            'quantidade' => 10.0,
            'valorUnitario' => 2.5,
            'valorTotal' => 25.0,
            'valorOrcamentoExercicio' => 25.0,
            'dataDesejada' => '2025-05-05',
            'unidadeRequisitante' => 'DEPARTAMENTO',
            'grupoContratacaoCodigo' => '170201-8/2025',
            'grupoContratacaoNome' => 'Dispensa de materiais',
            'dataPublicacaoPncp' => '2024-03-21T12:32:51',
            'dataInclusao' => '2024-03-21T12:32:51',
            'dataAtualizacao' => '2024-03-21T12:32:51',
        ];

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItens')
            ->once()
            ->with('12345678000199', 2026, 1, ['pagina' => 1, 'tamanhoPagina' => 50])
            ->andReturn([$item]);

        $job = new ProcessPcaItemsPageJob('12345678000199', 2026, 1, 1, 50, $syncId);

        $job->handle(
            $client,
            $this->app->make(PncpCircuitBreaker::class),
            $this->app->make(PncpFailureHandler::class),
            $tracker,
            $this->app->make(SyncPcaItemsAction::class),
        );

        $this->assertDatabaseHas('itens_pca', [
            'plano_pca_id' => $plan->id,
            'numero_item' => 1,
            'categoria_item_pca' => 1,
            'categoria_item_pca_nome' => 'Material',
            'pdm_codigo' => '5849',
            'pdm_nome' => 'COPO DESCARTAVEL',
            'descricao_item' => 'Descricao vinda do campo descricao',
            'grupo_contratacao_codigo' => '170201-8/2025',
        ]);

        $this->assertDatabaseHas('ciclos_pca', [
            'sync_id' => $syncId,
            'status' => 'finished',
            'paginas_completas' => 1,
            'itens_processados' => 1,
        ]);
    }
}
