<?php

namespace Tests\Feature\Console;

use App\Jobs\Pca\SyncPcaDataJob;
use App\Jobs\Pncp\DiscoverPncpUnidadesJob;
use App\Models\Orgaos\Orgao;
use App\Models\Pca\PcaPlan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SyncPcaCommandTest extends TestCase
{
    public function test_skips_organs_recently_verified_as_having_no_pca()
    {
        Bus::fake();

        $this->orgao('00000000000001');                                                  // nunca verificado
        $this->orgao('00000000000002', temPlano: true, verificadoHa: 1);                 // com PCA
        $this->orgao('00000000000003', temPlano: false, verificadoHa: 5);                // sem PCA, recente -> pula
        $this->orgao('00000000000004', temPlano: false, verificadoHa: 45);               // sem PCA, verificação velha
        $this->orgao('00000000000005', temPlano: false, verificadoHa: 5, ano: 2025);     // sem PCA, mas em outro ano

        $this->artisan('pncp:sync-pca', ['--ano' => 2026])->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['00000000000001', '00000000000002', '00000000000004', '00000000000005'],
            $this->dispatchedCnpjs(SyncPcaDataJob::class)
        );
    }

    public function test_force_empty_includes_organs_verified_as_empty()
    {
        Bus::fake();
        $this->orgao('00000000000003', temPlano: false, verificadoHa: 5);

        $this->artisan('pncp:sync-pca', ['--ano' => 2026, '--force-empty' => true])->assertSuccessful();

        $this->assertSame(['00000000000003'], $this->dispatchedCnpjs(SyncPcaDataJob::class));
    }

    public function test_explicit_cnpj_ignores_the_empty_memory()
    {
        Bus::fake();
        $this->orgao('00000000000003', temPlano: false, verificadoHa: 5);

        $this->artisan('pncp:sync-pca', ['--ano' => 2026, '--cnpj' => '00000000000003'])->assertSuccessful();

        $this->assertSame(['00000000000003'], $this->dispatchedCnpjs(SyncPcaDataJob::class));
    }

    public function test_inactive_organs_are_never_synced()
    {
        Bus::fake();
        Orgao::create(['cnpj' => '00000000000009', 'ativo' => false]);

        $this->artisan('pncp:sync-pca', ['--ano' => 2026])->assertSuccessful();

        Bus::assertNotDispatched(SyncPcaDataJob::class);
    }

    public function test_discover_unidades_only_targets_organs_that_have_plans()
    {
        Bus::fake();

        $this->orgao('00000000000001');
        $this->orgao('00000000000002');
        PcaPlan::create(['cnpj_orgao' => '00000000000002', 'ano_pca' => 2026, 'sequencial_pca' => 1]);

        $this->artisan('pncp:discover-unidades', ['--ano' => 2026])->assertSuccessful();

        $this->assertSame(['00000000000002'], $this->dispatchedCnpjs(DiscoverPncpUnidadesJob::class));
    }

    private function orgao(string $cnpj, ?bool $temPlano = null, int $verificadoHa = 0, int $ano = 2026): Orgao
    {
        return Orgao::create([
            'cnpj'               => $cnpj,
            'ativo'              => true,
            'pca_tem_plano'      => $temPlano,
            'pca_ano_verificado' => $temPlano === null ? null : $ano,
            'pca_verificado_em'  => $temPlano === null ? null : now()->subDays($verificadoHa),
        ]);
    }

    /**
     * @return list<string>
     */
    private function dispatchedCnpjs(string $jobClass): array
    {
        return Bus::dispatched($jobClass)
            ->map(function ($job)
            {
                return (fn () => $this->dto->cnpj ?? $this->cnpj)->call($job);
            })
            ->values()
            ->all();
    }
}
