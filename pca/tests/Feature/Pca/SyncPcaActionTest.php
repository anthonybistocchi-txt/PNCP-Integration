<?php

namespace Tests\Feature\Pca;

use App\Actions\Pca\SyncPcaPlanAction;
use App\DTOs\Pca\PcaPlanData;
use App\Models\Pca\PcaPlan;
use Tests\TestCase;

class SyncPcaActionTest extends TestCase
{
    public function test_upsert_idempotency()
    {
        $action = new SyncPcaPlanAction();

        $plan = new PcaPlanData(
            cnpjOrgao:     '12345678000199',
            anoPca:        2026,
            sequencialPca: 1,
            situacaoPca:   'Aprovado'
        );

        // Primeiro upsert
        $action->handle([$plan]);
        $this->assertDatabaseCount('planos_pca', 1);

        // Segundo upsert com alteração
        $plan2 = new PcaPlanData(
            cnpjOrgao:     '12345678000199',
            anoPca:        2026,
            sequencialPca: 1,
            situacaoPca:   'Cancelado'
        );
        $action->handle([$plan2]);

        $this->assertDatabaseCount('planos_pca', 1);
        $this->assertDatabaseHas('planos_pca', [
            'cnpj_orgao'   => '12345678000199',
            'situacao_pca' => 'Cancelado'
        ]);
    }
}
