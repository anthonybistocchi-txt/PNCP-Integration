<?php

namespace Tests\Feature\Pca;

use App\DTOs\Pca\ListPcaItemsDTO;
use App\Models\Pca\PcaItem;
use App\Models\Pca\PcaPlan;
use App\Repositories\Pca\PcaItemRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PcaItemRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_by_ano()
    {
        $plan2026 = PcaPlan::create([
            'cnpj_orgao' => '111',
            'ano_pca' => 2026,
            'sequencial_pca' => 1,
            'data_publicacao_pncp' => now()
        ]);
        
        PcaItem::create([
            'pca_plan_id' => $plan2026->id,
            'numero_item' => 1,
            'descricao_item' => 'Notebook 2026',
        ]);

        $plan2027 = PcaPlan::create([
            'cnpj_orgao' => '111',
            'ano_pca' => 2027,
            'sequencial_pca' => 2,
            'data_publicacao_pncp' => now()
        ]);

        PcaItem::create([
            'pca_plan_id' => $plan2027->id,
            'numero_item' => 1,
            'descricao_item' => 'Notebook 2027',
        ]);

        $repository = new PcaItemRepository();
        $result = $repository->list(new ListPcaItemsDTO(ano: 2026));

        $this->assertCount(1, $result['data']);
        $this->assertEquals('Notebook 2026', $result['data'][0]->descricao_item);
    }
}
