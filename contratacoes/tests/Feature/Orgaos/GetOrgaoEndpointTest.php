<?php

namespace Tests\Feature\Orgaos;

use App\Models\Auth\User;
use App\Models\Orgaos\Orgao;
use App\Models\Pca\PcaItem;
use App\Models\Pca\PcaPlan;
use Illuminate\Support\Str;
use Tests\TestCase;

class GetOrgaoEndpointTest extends TestCase
{
    public function test_returns_orgao_with_its_plans_and_items()
    {
        $apiKey = (string) Str::uuid();

        User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'secret123',
            'api_key' => $apiKey,
        ]);

        Orgao::create(['cnpj' => '12345678000199', 'razao_social' => 'Órgão A', 'ativo' => true]);

        $plan = PcaPlan::create([
            'cnpj_orgao'     => '12345678000199',
            'ano_pca'        => 2026,
            'sequencial_pca' => 1,
        ]);

        PcaItem::create([
            'plano_pca_id'   => $plan->id,
            'numero_item'    => 1,
            'descricao_item' => 'Notebook',
        ]);

        $response = $this->json('QUERY', '/api/v1/orgaos/12345678000199', [], ['API_KEY' => $apiKey]);

        $response->assertOk()
            ->assertJsonPath('data.cnpj', '12345678000199')
            ->assertJsonPath('data.razao_social', 'Órgão A')
            ->assertJsonPath('data.planos.0.sequencial_pca', 1)
            ->assertJsonPath('data.planos.0.ano_pca', 2026)
            ->assertJsonPath('data.planos.0.itens.0.numero_item', 1)
            ->assertJsonPath('data.planos.0.itens.0.descricao_item', 'Notebook');
    }

    public function test_returns_404_when_orgao_does_not_exist()
    {
        $apiKey = (string) Str::uuid();

        User::create([
            'name'     => 'Test User',
            'email'    => 'test@example.com',
            'password' => 'secret123',
            'api_key'  => $apiKey,
        ]);

        $response = $this->json('QUERY', '/api/v1/orgaos/00000000000000', [], ['API_KEY' => $apiKey]);

        $response->assertNotFound();
    }
}
