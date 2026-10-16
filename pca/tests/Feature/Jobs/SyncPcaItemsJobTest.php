<?php

namespace Tests\Feature\Jobs;

use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Jobs\Pca\ProcessPcaItemsPageJob;
use App\Jobs\Pca\SyncPcaItemsJob;
use App\Models\Pca\PcaPlan;
use App\Repositories\Pca\PcaPlanRepository;
use App\Repositories\Pca\PncpPcaCicloRepository;
use App\Support\ErrorHandling\PncpFailureHandler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SyncPcaItemsJobTest extends TestCase
{
    public function test_uses_500_items_per_page_by_default()
    {
        Bus::fake();

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')->once()->andReturn(1200);

        $job = new SyncPcaItemsJob('12345678000199', 2026, 1);

        $job->handle(
            $client,
            $this->app->make(PncpCircuitBreaker::class),
            $this->app->make(PncpFailureHandler::class),
            $this->app->make(PncpSyncCycleTracker::class),
            $this->app->make(PcaPlanRepository::class),
            $this->app->make(PncpPcaCicloRepository::class),
        );

        // 1200 itens / 500 por página = 3 páginas (500 + 500 + 200).
        Bus::assertDispatchedTimes(ProcessPcaItemsPageJob::class, 3);
        Bus::assertDispatched(
            ProcessPcaItemsPageJob::class,
            fn (ProcessPcaItemsPageJob $page) => (fn () => $this->tamanhoPagina)->call($page) === 500
        );

        $this->assertDatabaseHas('ciclos_pca', [
            'sequencial_pca'  => 1,
            'total_anunciado' => 1200,
            'total_paginas'   => 3,
        ]);
    }

    public function test_page_size_comes_from_config()
    {
        Bus::fake();
        config(['pncp.pca.page_size' => 50]);

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')->once()->andReturn(120);

        (new SyncPcaItemsJob('12345678000199', 2026, 1))->handle(
            $client,
            $this->app->make(PncpCircuitBreaker::class),
            $this->app->make(PncpFailureHandler::class),
            $this->app->make(PncpSyncCycleTracker::class),
            $this->app->make(PcaPlanRepository::class),
            $this->app->make(PncpPcaCicloRepository::class),
        );

        Bus::assertDispatchedTimes(ProcessPcaItemsPageJob::class, 3);
    }

    public function test_is_unique_per_plan_for_the_retry_window()
    {
        config(['pncp.retry_until_hours.pca' => 72]);

        $job = new SyncPcaItemsJob('12345678000199', 2026, 7);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('12345678000199:2026:7', $job->uniqueId());
        $this->assertSame(72 * 3600, $job->uniqueFor());
    }

    public function test_derives_page_count_from_itens_quantidade()
    {
        Bus::fake();
        config(['pncp.pca.page_size' => 50]);

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')
            ->once()
            ->with('12345678000199', 2026, 1)
            ->andReturn(120);

        $job = new SyncPcaItemsJob('12345678000199', 2026, 1);

        $job->handle(
            $client,
            $this->app->make(PncpCircuitBreaker::class),
            $this->app->make(PncpFailureHandler::class),
            $this->app->make(PncpSyncCycleTracker::class),
            $this->app->make(PcaPlanRepository::class),
            $this->app->make(PncpPcaCicloRepository::class),
        );

        // 120 itens / 50 por página = 3 páginas.
        Bus::assertDispatchedTimes(ProcessPcaItemsPageJob::class, 3);

        $this->assertDatabaseHas('ciclos_pca', [
            'cnpj_orgao'      => '12345678000199',
            'ano_pca'         => 2026,
            'sequencial_pca'  => 1,
            'total_anunciado' => 120,
            'total_paginas'   => 3,
            'status'          => 'started',
        ]);
    }

    public function test_pca_without_items_creates_an_empty_cycle_and_dispatches_nothing()
    {
        Bus::fake();

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')->once()->andReturn(0);

        $job = new SyncPcaItemsJob('12345678000199', 2026, 1);

        $job->handle(
            $client,
            $this->app->make(PncpCircuitBreaker::class),
            $this->app->make(PncpFailureHandler::class),
            $this->app->make(PncpSyncCycleTracker::class),
            $this->app->make(PcaPlanRepository::class),
            $this->app->make(PncpPcaCicloRepository::class),
        );

        Bus::assertNotDispatched(ProcessPcaItemsPageJob::class);

        $this->assertDatabaseHas('ciclos_pca', [
            'cnpj_orgao'     => '12345678000199',
            'ano_pca'        => 2026,
            'sequencial_pca' => 1,
            'status'         => 'empty',
        ]);
    }

    public function test_a_404_on_the_quantity_is_not_mistaken_for_an_empty_plan()
    {
        Bus::fake();

        $plan = PcaPlan::create([
            'cnpj_orgao'     => '12345678000199',
            'ano_pca'        => 2026,
            'sequencial_pca' => 1,
        ]);

        // null = 404; 0 seria "plano existente sem itens".
        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')->once()->andReturn(null);

        $this->runHandle(new SyncPcaItemsJob('12345678000199', 2026, 1), $client);

        Bus::assertNotDispatched(ProcessPcaItemsPageJob::class);
        $this->assertDatabaseCount('ciclos_pca', 0);
        // Sem marca d'água: o plano segue pendente e o faltantes tenta de novo.
        $this->assertNull($plan->refresh()->itens_sincronizados_em);
    }

    public function test_failed_finds_the_cycle_through_the_payload_sync_id()
    {
        Bus::fake();
        config(['pncp.pca.page_size' => 50]);

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')->once()->andReturn(120);

        $job = new SyncPcaItemsJob('12345678000199', 2026, 1);
        $this->runHandle($job, $client);

        $this->assertDatabaseHas('ciclos_pca', ['sequencial_pca' => 1, 'status' => 'started']);

        // O worker chama failed() numa instância desserializada, que nunca
        // viu o que o handle() atribuiu.
        unserialize(serialize($job))->failed(new \RuntimeException('prazo vencido'));

        $this->assertDatabaseCount('ciclos_pca', 1);
        $this->assertDatabaseHas('ciclos_pca', ['sequencial_pca' => 1, 'status' => 'abandoned']);
    }

    public function test_failed_before_the_cycle_exists_records_one_abandoned_cycle()
    {
        $job = new SyncPcaItemsJob('12345678000199', 2026, 1);

        unserialize(serialize($job))->failed(new \RuntimeException('API fora do ar'));

        $this->assertDatabaseCount('ciclos_pca', 1);
        $this->assertDatabaseHas('ciclos_pca', [
            'cnpj_orgao'     => '12345678000199',
            'sequencial_pca' => 1,
            'status'         => 'abandoned',
        ]);
    }

    public function test_a_retry_resumes_the_same_cycle_without_measuring_again()
    {
        Bus::fake();
        config(['pncp.pca.page_size' => 50]);

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')->once()->andReturn(120);

        $job = new SyncPcaItemsJob('12345678000199', 2026, 1);
        $this->runHandle($job, $client);

        // Nova tentativa do mesmo job (ex.: o despacho das páginas falhou no meio).
        $retryClient = Mockery::mock(PncpClient::class);
        $retryClient->shouldNotReceive('getPcaItensQuantidade');
        $this->runHandle(unserialize(serialize($job)), $retryClient);

        $this->assertDatabaseCount('ciclos_pca', 1);
        // Páginas reenfileiradas: duplicar uma página é inofensivo (upsert + contagem única).
        Bus::assertDispatchedTimes(ProcessPcaItemsPageJob::class, 6);
    }

    public function test_a_retry_does_not_reset_the_progress_of_pages_already_done()
    {
        Bus::fake();
        config(['pncp.pca.page_size' => 50]);

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')->once()->andReturn(120);

        $job = new SyncPcaItemsJob('12345678000199', 2026, 1);
        $this->runHandle($job, $client);

        $syncId  = (fn () => $this->syncId)->call($job);
        $tracker = $this->app->make(PncpSyncCycleTracker::class);
        $tracker->markPageDone($syncId, 50, '1');

        $this->runHandle(unserialize(serialize($job)), Mockery::mock(PncpClient::class));

        $this->assertSame(1, (int) Cache::get("pncp_sync:{$syncId}:done"));
    }

    public function test_a_job_serialized_before_the_constructor_id_still_gets_its_own_cycle()
    {
        Bus::fake();
        config(['pncp.pca.page_size' => 50]);

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')->once()->andReturn(60);

        // Payload anterior ao id no construtor: o __unserialize do Laravel não
        // traz a chave, e a propriedade tipada fica não inicializada. Atribuir
        // '' não reproduz o Error do log.
        $job = $this->restoreWithoutSyncId(new SyncPcaItemsJob('12345678000199', 2026, 1));
        $job->failed(new \RuntimeException('prazo vencido'));

        $this->runHandle($job, $client);

        $this->assertDatabaseMissing('ciclos_pca', ['sync_id' => '']);
        $this->assertDatabaseHas('ciclos_pca', ['sequencial_pca' => 1, 'status' => 'started']);
    }

    public function test_empty_plan_gets_watermark_so_it_is_not_pending_forever()
    {
        Bus::fake();

        $plan = PcaPlan::create([
            'cnpj_orgao'     => '12345678000199',
            'ano_pca'        => 2026,
            'sequencial_pca' => 1,
        ]);

        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaItensQuantidade')->once()->andReturn(0);

        (new SyncPcaItemsJob('12345678000199', 2026, 1))->handle(
            $client,
            $this->app->make(PncpCircuitBreaker::class),
            $this->app->make(PncpFailureHandler::class),
            $this->app->make(PncpSyncCycleTracker::class),
            $this->app->make(PcaPlanRepository::class),
            $this->app->make(PncpPcaCicloRepository::class),
        );

        $plan->refresh();
        $this->assertNotNull($plan->itens_sincronizados_em);
        $this->assertSame(0, $plan->itens_total_sincronizado);
    }

    private function restoreWithoutSyncId(SyncPcaItemsJob $job): SyncPcaItemsJob
    {
        $payload = $job->__serialize();
        unset($payload["\0*\0syncId"]);

        /** @var SyncPcaItemsJob $restored */
        $restored = (new \ReflectionClass($job))->newInstanceWithoutConstructor();
        $restored->__unserialize($payload);

        return $restored;
    }

    private function runHandle(SyncPcaItemsJob $job, PncpClient $client): void
    {
        $job->handle(
            $client,
            $this->app->make(PncpCircuitBreaker::class),
            $this->app->make(PncpFailureHandler::class),
            $this->app->make(PncpSyncCycleTracker::class),
            $this->app->make(PcaPlanRepository::class),
            $this->app->make(PncpPcaCicloRepository::class),
        );
    }
}
