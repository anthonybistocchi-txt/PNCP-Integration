<?php

namespace Tests\Feature\Console;

use App\Jobs\Pca\SyncPcaDataJob;
use App\Jobs\Pca\SyncPcaItemsJob;
use App\Models\Pca\PcaPlan;
use App\Models\Pca\PncpPcaCiclo;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncPcaFaltantesCommandTest extends TestCase
{
    private const CNPJ = '12345678000199';

    public function test_dispatches_only_plans_whose_items_are_not_up_to_date()
    {
        Bus::fake();

        $baixadoEm = now()->subDay();

        // 1: nunca baixado -> pendente.
        $this->plan(1, syncedAt: null);
        // 2: baixado e sem atualização posterior -> em dia.
        $this->plan(2, syncedAt: $baixadoEm, atualizadoEm: $baixadoEm->copy()->subDay());
        // 3: o PNCP atualizou depois do download -> pendente.
        $this->plan(3, syncedAt: $baixadoEm, atualizadoEm: $baixadoEm->copy()->addHours(3));
        // 4: nunca baixado, mas com ciclo vivo na fila -> não duplica.
        $this->plan(4, syncedAt: null);
        $this->ciclo(4, 'started', startedAt: now()->subHours(3));
        // 5: ciclo antigo abandonado, sem marca -> pendente.
        $this->plan(5, syncedAt: null);
        $this->ciclo(5, 'abandoned', startedAt: now()->subDays(2));
        // 6: ciclo antigo abandonado, mas depois recuperado (marca em dia) -> NÃO volta.
        $this->plan(6, syncedAt: $baixadoEm, atualizadoEm: $baixadoEm->copy()->subDay());
        $this->ciclo(6, 'abandoned', startedAt: now()->subDays(5));
        // 7: started antigo (job já venceu) sem marca -> pendente.
        $this->plan(7, syncedAt: null);
        $this->ciclo(7, 'started', startedAt: now()->subDays(5));

        $this->artisan('pncp:sync-pca-faltantes', ['--ano' => 2026])->assertSuccessful();

        $this->assertEqualsCanonicalizing([1, 3, 5, 7], $this->dispatchedSequencials());
    }

    public function test_dry_run_dispatches_nothing()
    {
        Bus::fake();
        $this->plan(1, syncedAt: null);

        $this->artisan('pncp:sync-pca-faltantes', ['--ano' => 2026, '--dry-run' => true])
            ->expectsOutputToContain('1 pendente(s)')
            ->assertSuccessful();

        Bus::assertNotDispatched(SyncPcaItemsJob::class);
    }

    public function test_limit_caps_the_plans_dispatched_per_run()
    {
        Bus::fake();
        $this->plan(1, syncedAt: null);
        $this->plan(2, syncedAt: null);
        $this->plan(3, syncedAt: null);

        $this->artisan('pncp:sync-pca-faltantes', ['--ano' => 2026, '--limit' => 2])
            ->expectsOutputToContain('1 plano(s) pendente(s) ficaram para a próxima execução')
            ->assertSuccessful();

        $this->assertCount(2, $this->dispatchedSequencials());
    }

    public function test_never_synced_plans_come_before_old_ones_when_capped()
    {
        Bus::fake();

        $this->plan(1, syncedAt: now()->subDays(40));  // marca velha (refresh) -> pendente
        $this->plan(2, syncedAt: null);                 // nunca -> vem primeiro

        $this->artisan('pncp:sync-pca-faltantes', ['--ano' => 2026, '--limit' => 1])->assertSuccessful();

        $this->assertSame([2], $this->dispatchedSequencials());
    }

    public function test_retries_an_organ_whose_latest_listing_died(): void
    {
        Bus::fake();

        PncpPcaCiclo::create([
            'sync_id'    => (string) Str::uuid(),
            'cnpj_orgao' => self::CNPJ,
            'ano_pca'    => 2026,
            'status'     => 'abandoned',
            'started_at' => now()->subDays(4),
            'finished_at' => now()->subDays(4),
        ]);

        $this->artisan('pncp:sync-pca-faltantes', ['--ano' => 2026])->assertSuccessful();

        Bus::assertDispatched(SyncPcaDataJob::class, function (SyncPcaDataJob $job)
        {
            return (fn () => $this->dto->cnpj)->call($job) === self::CNPJ;
        });
    }

    public function test_a_later_finished_listing_is_not_retried(): void
    {
        Bus::fake();

        PncpPcaCiclo::create([
            'sync_id'    => (string) Str::uuid(),
            'cnpj_orgao' => self::CNPJ,
            'ano_pca'    => 2026,
            'status'     => 'abandoned',
            'started_at' => now()->subDays(5),
            'finished_at' => now()->subDays(5),
        ]);
        PncpPcaCiclo::create([
            'sync_id'    => (string) Str::uuid(),
            'cnpj_orgao' => self::CNPJ,
            'ano_pca'    => 2026,
            'status'     => 'finished',
            'started_at' => now()->subDays(1),
            'finished_at' => now()->subDay(),
        ]);

        $this->artisan('pncp:sync-pca-faltantes', ['--ano' => 2026])->assertSuccessful();

        Bus::assertNotDispatched(SyncPcaDataJob::class);
    }

    public function test_filters_by_cnpj()
    {
        Bus::fake();
        $this->plan(1, syncedAt: null);
        PcaPlan::create(['cnpj_orgao' => '99999999000191', 'ano_pca' => 2026, 'sequencial_pca' => 1]);

        $this->artisan('pncp:sync-pca-faltantes', ['--ano' => 2026, '--cnpj' => self::CNPJ])->assertSuccessful();

        $this->assertSame([1], $this->dispatchedSequencials());
    }

    public function test_global_pca_update_date_also_makes_a_plan_pending()
    {
        Bus::fake();

        $baixadoEm = now()->subDay();
        $porData   = fn (CarbonInterface $data) => (object) ['dataAtualizacaoGlobalPCA' => $data->format('Y-m-d\TH:i:s')];

        // 1: data da unidade antiga, mas a global do PCA é posterior ao download -> pendente
        // (o SyncPcaDataJob já decide assim; o faltantes precisa concordar).
        PcaPlan::create([
            'cnpj_orgao'             => self::CNPJ,
            'ano_pca'                => 2026,
            'sequencial_pca'         => 1,
            'data_atualizacao'       => $baixadoEm->copy()->subDays(2),
            'itens_sincronizados_em' => $baixadoEm,
            'payload_completo'       => $porData($baixadoEm->copy()->addHours(5)),
        ]);
        // 2: a global também é anterior ao download -> em dia.
        PcaPlan::create([
            'cnpj_orgao'             => self::CNPJ,
            'ano_pca'                => 2026,
            'sequencial_pca'         => 2,
            'data_atualizacao'       => $baixadoEm->copy()->subDays(2),
            'itens_sincronizados_em' => $baixadoEm,
            'payload_completo'       => $porData($baixadoEm->copy()->subDay()),
        ]);
        // 3: sem payload (planos antigos) -> só vale a data da unidade, em dia.
        $this->plan(3, syncedAt: $baixadoEm, atualizadoEm: $baixadoEm->copy()->subDay());

        $this->artisan('pncp:sync-pca-faltantes', ['--ano' => 2026])->assertSuccessful();

        $this->assertSame([1], $this->dispatchedSequencials());
    }

    public function test_reports_gap_cycles_without_dispatching_them_blindly()
    {
        Bus::fake();
        $this->plan(1, syncedAt: now()->subDay(), atualizadoEm: now()->subDays(3));
        $this->ciclo(1, 'abandoned', startedAt: now()->subDays(5));

        $this->artisan('pncp:sync-pca-faltantes', ['--ano' => 2026])
            ->expectsOutputToContain('Ciclos de plano em gap')
            ->assertSuccessful();

        // O ciclo antigo aparece no relatório, mas o plano está em dia.
        Bus::assertNotDispatched(SyncPcaItemsJob::class);
    }

    private function plan(int $sequencial, ?CarbonInterface $syncedAt, ?CarbonInterface $atualizadoEm = null): PcaPlan
    {
        return PcaPlan::create([
            'cnpj_orgao'             => self::CNPJ,
            'ano_pca'                => 2026,
            'sequencial_pca'         => $sequencial,
            'data_atualizacao'       => $atualizadoEm,
            'itens_sincronizados_em' => $syncedAt,
        ]);
    }

    private function ciclo(int $sequencial, string $status, CarbonInterface $startedAt): void
    {
        PncpPcaCiclo::create([
            'sync_id'           => (string) Str::uuid(),
            'cnpj_orgao'        => self::CNPJ,
            'ano_pca'           => 2026,
            'sequencial_pca'    => $sequencial,
            'total_anunciado'   => 10,
            'total_paginas'     => 1,
            'itens_processados' => 0,
            'status'            => $status,
            'started_at'        => $startedAt,
            'finished_at'       => $status === 'started' ? null : $startedAt,
        ]);
    }

    /**
     * @return list<int>
     */
    private function dispatchedSequencials(): array
    {
        return Bus::dispatched(SyncPcaItemsJob::class)
            ->map(fn (SyncPcaItemsJob $job) => (fn () => $this->sequencial)->call($job))
            ->values()
            ->all();
    }
}
