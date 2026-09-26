<?php

namespace Tests\Feature\Orgaos;

use App\Models\Auth\User;
use App\Models\Orgaos\Orgao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListOrgaosEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_orgaos_with_cursor_pagination()
    {
        $apiKey = (string) Str::uuid();

        User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'secret123',
            'api_key' => $apiKey,
        ]);

        Orgao::create(['cnpj' => '12345678000199', 'razao_social' => 'Órgão A', 'ativo' => true]);
        Orgao::create(['cnpj' => '98765432000199', 'razao_social' => 'Órgão B', 'ativo' => true]);
        Orgao::create(['cnpj' => '55555555000155', 'razao_social' => 'Órgão Inativo', 'ativo' => false]);

        $response = $this->json('QUERY', '/api/v1/orgaos', [], ['API_KEY' => $apiKey]);

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.cnpj', '12345678000199')
            ->assertJsonPath('data.1.cnpj', '98765432000199');
    }
}
