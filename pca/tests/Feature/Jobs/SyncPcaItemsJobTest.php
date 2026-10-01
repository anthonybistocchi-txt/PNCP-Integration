<?php

namespace Tests\Feature\Jobs;

use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Jobs\Pca\ProcessPcaItemsPageJob;
use App\Jobs\Pca\SyncPcaItemsJob;
use App\Support\ErrorHandling\PncpFailureHandler;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class SyncPcaItemsJobTest extends TestCase
{
    public function test_derives_page_count_from_itens_quantidade()
    {
        Bus::fake();

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
        );

        Bus::assertNotDispatched(ProcessPcaItemsPageJob::class);

        $this->assertDatabaseHas('ciclos_pca', [
            'cnpj_orgao'     => '12345678000199',
            'ano_pca'        => 2026,
            'sequencial_pca' => 1,
            'status'         => 'empty',
        ]);
    }
}
