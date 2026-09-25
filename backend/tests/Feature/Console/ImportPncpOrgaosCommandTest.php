<?php

namespace Tests\Feature\Console;

use App\Repositories\Pncp\PncpOrgaoRepository;
use Mockery;
use Tests\TestCase;

class ImportPncpOrgaosCommandTest extends TestCase
{
    public function test_imports_discovered_orgaos_and_skips_manually_managed_ones()
    {
        $capturedRows = null;

        $repository = Mockery::mock(PncpOrgaoRepository::class);
        $repository->shouldReceive('distinctOrgaosFromContratacoes')->andReturn(collect([
            (object) ['cnpj' => '11111111000111', 'razao_social' => 'ORGAO AUTO', 'poder' => 'E', 'esfera' => 'F'],
            (object) ['cnpj' => '22222222000122', 'razao_social' => 'ORGAO MANUAL', 'poder' => 'E', 'esfera' => 'F'],
        ]));
        $repository->shouldReceive('manualCnpjs')->andReturn(collect(['22222222000122' => '22222222000122']));
        $repository->shouldReceive('autoImportedCnpjs')->andReturn(collect());
        $repository->shouldReceive('upsertMany')->andReturnUsing(function (array $rows) use (&$capturedRows) {
            $capturedRows = $rows;
        });

        $this->app->instance(PncpOrgaoRepository::class, $repository);

        $this->artisan('pncp:import-orgaos')
            ->expectsOutputToContain('Manuais ignorados: 1')
            ->assertSuccessful();

        $this->assertCount(1, $capturedRows);
        $this->assertSame('11111111000111', $capturedRows[0]['cnpj']);
        $this->assertSame('public_contratations', $capturedRows[0]['importado_de']);
    }
}
