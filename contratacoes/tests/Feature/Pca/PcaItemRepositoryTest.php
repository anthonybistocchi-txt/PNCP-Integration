<?php

namespace Tests\Feature\Pca;

use App\DTOs\Pca\ListPcaItemsDTO;
use App\Models\Pca\PcaItem;
use App\Models\Pca\PcaPlan;
use App\Repositories\Pca\PcaItemRepository;
use Tests\TestCase;

class PcaItemRepositoryTest extends TestCase
{
    public function test_filter_by_ano()
    {
        $plan2026 = PcaPlan::create([
            'cnpj_orgao' => '111',
            'ano_pca' => 2026,
            'sequencial_pca' => 1,
            'data_publicacao_pncp' => now(),
        ]);

        PcaItem::create([
            'plano_pca_id' => $plan2026->id,
            'numero_item' => 1,
            'descricao_item' => 'Notebook 2026',
        ]);

        $plan2027 = PcaPlan::create([
            'cnpj_orgao' => '111',
            'ano_pca' => 2027,
            'sequencial_pca' => 2,
            'data_publicacao_pncp' => now(),
        ]);

        PcaItem::create([
            'plano_pca_id' => $plan2027->id,
            'numero_item' => 1,
            'descricao_item' => 'Notebook 2027',
        ]);

        $repository = $this->app->make(PcaItemRepository::class);
        $result = $repository->list(new ListPcaItemsDTO(ano: 2026));

        $this->assertCount(1, $result->data);
        $this->assertEquals('Notebook 2026', $result->data->first()->descricao_item);
        $this->assertFalse($result->has_more);
        $this->assertNull($result->next_cursor);
    }

    public function test_keyset_pagination_returns_cursor_without_duplicates()
    {
        $plan = PcaPlan::create([
            'cnpj_orgao' => '111',
            'ano_pca' => 2026,
            'sequencial_pca' => 1,
            'data_publicacao_pncp' => now(),
        ]);

        for ($i = 1; $i <= 3; $i++) {
            PcaItem::create([
                'plano_pca_id' => $plan->id,
                'numero_item' => $i,
                'descricao_item' => "Item {$i}",
                'data_publicacao_pncp' => now()->subDays($i),
            ]);
        }

        $repository = $this->app->make(PcaItemRepository::class);

        $firstPage = $repository->list(new ListPcaItemsDTO(ano: 2026, limit: 2));

        $this->assertCount(2, $firstPage->data);
        $this->assertTrue($firstPage->has_more);
        $this->assertNotNull($firstPage->next_cursor);
        $this->assertSame(1, $firstPage->page);
        $this->assertSame(3, $firstPage->count);

        $secondPage = $repository->list(new ListPcaItemsDTO(
            ano: 2026,
            limit: 2,
            cursor: $firstPage->next_cursor,
        ));

        $this->assertCount(1, $secondPage->data);
        $this->assertFalse($secondPage->has_more);

        $ids = $firstPage->data->pluck('id')->merge($secondPage->data->pluck('id'));

        $this->assertCount(3, $ids->unique());
    }
}
