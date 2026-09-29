<?php

namespace Tests\Feature\Jobs;

use App\DTOs\Pca\PcaSyncParamsDTO;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Jobs\Pca\SyncPcaDataJob;
use App\Models\Pca\PncpPcaCiclo;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Mockery;

class SyncPcaDataJobTest extends TestCase
{
    public function test_empty_payload_finishes_gracefully()
    {
        Bus::fake();

        $clientMock = Mockery::mock(PncpClient::class);
        $clientMock->shouldReceive('getPcaUnidades')->andReturn([]);
        $this->app->instance(PncpClient::class, $clientMock);

        $dto = new PcaSyncParamsDTO(ano: 2026, cnpj: '123');
        $job = new SyncPcaDataJob($dto);

        // Ação
        $job->handle(
            $clientMock,
            $this->app->make(PncpCircuitBreaker::class),
            $this->app->make(\App\Support\ErrorHandling\PncpFailureHandler::class),
            $this->app->make(\App\Actions\Pca\SyncPcaPlanAction::class)
        );

        $this->assertDatabaseCount('ciclos_pca', 0);
    }
}
