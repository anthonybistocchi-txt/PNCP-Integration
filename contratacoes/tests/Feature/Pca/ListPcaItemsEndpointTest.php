<?php

namespace Tests\Feature\Pca;

use App\Models\Auth\User;
use App\Models\Pca\PcaItem;
use App\Models\Pca\PcaPlan;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListPcaItemsEndpointTest extends TestCase
{
    public function test_lists_itens_pca_through_the_full_layer_stack()
    {
        $apiKey = (string) Str::uuid();

        User::create([
            'name'     => 'Test User',
            'email'    => 'test@example.com',
            'password' => 'secret123',
            'api_key'  => $apiKey,
        ]);

        $plan = PcaPlan::create([
            'cnpj_orgao'           => '12345678000199',
            'ano_pca'              => 2026,
            'sequencial_pca'       => 1,
            'data_publicacao_pncp' => now(),
        ]);

        PcaItem::create([
            'plano_pca_id'    => $plan->id,
            'numero_item'    => 1,
            'descricao_item' => 'Notebook',
        ]);

        $response = $this->withHeaders(['API_KEY' => $apiKey])
            ->getJson('/api/v1/pca/items?ano=2026');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('count_page', 1)
            ->assertJsonPath('data.0.descricao_item', 'Notebook');
    }
}
