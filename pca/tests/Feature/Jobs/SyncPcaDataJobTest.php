<?php

namespace Tests\Feature\Jobs;

use App\Actions\Pca\SyncPcaPlanAction;
use App\DTOs\Pca\PcaSyncParamsDTO;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Jobs\Pca\SyncPcaDataJob;
use App\Jobs\Pca\SyncPcaItemsJob;
use App\Models\Orgaos\Orgao;
use App\Models\Pca\PcaPlan;
use App\Models\Pca\PncpPcaCiclo;
use App\Repositories\Orgaos\OrgaoRepository;
use App\Repositories\Pca\PcaPlanRepository;
use App\Repositories\Pca\PncpPcaCicloRepository;
use App\Support\ErrorHandling\PncpFailureHandler;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SyncPcaDataJobTest extends TestCase
{
    private const CNPJ = '12345678000199';

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
            $this->app->make(PncpFailureHandler::class),
            $this->app->make(SyncPcaPlanAction::class),
            $this->app->make(OrgaoRepository::class),
            $this->app->make(PcaPlanRepository::class),
            $this->app->make(PncpPcaCicloRepository::class),
        );

        $this->assertDatabaseCount('ciclos_pca', 0);
    }

    public function test_organ_without_pca_is_remembered_as_empty()
    {
        Bus::fake();
        Orgao::create(['cnpj' => self::CNPJ, 'ativo' => true]);

        $this->runMaster([]);

        $orgao = Orgao::where('cnpj', self::CNPJ)->first();
        $this->assertFalse($orgao->pca_tem_plano);
        $this->assertSame(2026, $orgao->pca_ano_verificado);
        $this->assertNotNull($orgao->pca_verificado_em);
        Bus::assertNotDispatched(SyncPcaItemsJob::class);
    }

    public function test_organ_with_pca_is_marked_and_new_plans_are_dispatched()
    {
        Bus::fake();
        Orgao::create(['cnpj' => self::CNPJ, 'ativo' => true]);

        $this->runMaster([$this->unidade(1, now()->subDays(3))]);

        $this->assertTrue(Orgao::where('cnpj', self::CNPJ)->first()->pca_tem_plano);
        $this->assertSame([1], $this->dispatchedSequencials());
    }

    public function test_only_pending_plans_get_their_items_downloaded()
    {
        Bus::fake();
        Orgao::create(['cnpj' => self::CNPJ, 'ativo' => true]);

        $baixadoEm = now()->subDay();

        // 2: itens baixados depois da última atualização -> em dia.
        $this->plan(2, syncedAt: $baixadoEm);
        // 3: o PNCP atualizou o plano depois do download -> pendente.
        $this->plan(3, syncedAt: $baixadoEm);
        // 4: nunca baixado, mas já tem ciclo vivo (páginas na fila) -> não duplica.
        $this->plan(4, syncedAt: null);
        PncpPcaCiclo::create([
            'sync_id'         => (string) Str::uuid(),
            'cnpj_orgao'      => self::CNPJ,
            'ano_pca'         => 2026,
            'sequencial_pca'  => 4,
            'total_anunciado' => 10,
            'total_paginas'   => 1,
            'status'          => 'started',
            'started_at'      => now()->subHours(2),
        ]);
        // 5: sem atualização nova, mas a marca passou de refresh_after_days -> pendente.
        $this->plan(5, syncedAt: now()->subDays(60));

        $this->runMaster([
            $this->unidade(1, now()->subDays(3)),               // sem linha: novo -> pendente
            $this->unidade(2, $baixadoEm->copy()->subDay()),
            $this->unidade(3, $baixadoEm->copy()->addHours(5)),
            $this->unidade(4, now()->subDays(3)),
            $this->unidade(5, now()->subDays(90)),
        ]);

        $this->assertEqualsCanonicalizing([1, 3, 5], $this->dispatchedSequencials());
    }

    public function test_latest_of_the_two_update_dates_decides()
    {
        Bus::fake();
        Orgao::create(['cnpj' => self::CNPJ, 'ativo' => true]);

        $baixadoEm = now()->subDay();
        $this->plan(1, syncedAt: $baixadoEm);

        // dataAtualizacao antiga, mas a global do PCA é posterior ao download.
        $unidade                           = $this->unidade(1, $baixadoEm->copy()->subDays(2));
        $unidade->dataAtualizacaoGlobalPCA = $baixadoEm->copy()->addHours(5)->format('Y-m-d\TH:i:s');

        $this->runMaster([$unidade]);

        $this->assertSame([1], $this->dispatchedSequencials());
    }

    public function test_a_retry_reuses_the_organ_cycle_instead_of_leaving_an_orphan()
    {
        Bus::fake();
        Orgao::create(['cnpj' => self::CNPJ, 'ativo' => true]);

        $job = new SyncPcaDataJob(new PcaSyncParamsDTO(ano: 2026, cnpj: self::CNPJ));
        $unidades = [$this->unidade(1, now()->subDays(3))];

        $this->handleMaster($job, $unidades);
        // Nova tentativa: o worker desserializa o mesmo payload.
        $this->handleMaster(unserialize(serialize($job)), $unidades);

        $this->assertSame(1, PncpPcaCiclo::whereNull('sequencial_pca')->count());
        $this->assertSame('finished', PncpPcaCiclo::whereNull('sequencial_pca')->first()->status);
    }

    public function test_failed_abandons_the_organ_cycle_found_through_the_payload()
    {
        $job    = new SyncPcaDataJob(new PcaSyncParamsDTO(ano: 2026, cnpj: self::CNPJ));
        $syncId = (fn () => $this->syncId)->call($job);

        PncpPcaCiclo::create([
            'sync_id'    => $syncId,
            'cnpj_orgao' => self::CNPJ,
            'ano_pca'    => 2026,
            'status'     => 'started',
            'started_at' => now()->subHour(),
        ]);

        // O worker chama failed() numa instância desserializada.
        unserialize(serialize($job))->failed(new \RuntimeException('prazo vencido'));

        $this->assertDatabaseHas('ciclos_pca', ['sync_id' => $syncId, 'status' => 'abandoned']);
    }

    public function test_a_job_serialized_before_the_constructor_id_still_gets_its_own_cycle()
    {
        Bus::fake();
        Orgao::create(['cnpj' => self::CNPJ, 'ativo' => true]);

        // Payload anterior ao id no construtor: chave ausente, propriedade não
        // inicializada. É o Error do log, não um syncId já igual a ''.
        $job = $this->restoreWithoutSyncId(new SyncPcaDataJob(new PcaSyncParamsDTO(ano: 2026, cnpj: self::CNPJ)));
        $job->failed(new \RuntimeException('prazo vencido'));

        $this->handleMaster($job, [$this->unidade(1, now()->subDays(3))]);

        $this->assertDatabaseMissing('ciclos_pca', ['sync_id' => '']);
        $this->assertSame(1, PncpPcaCiclo::whereNull('sequencial_pca')->count());
    }

    public function test_unique_for_follows_the_retry_window()
    {
        config(['pncp.retry_until_hours.pca' => 72]);

        $job = new SyncPcaDataJob(new PcaSyncParamsDTO(ano: 2026, cnpj: self::CNPJ));

        $this->assertSame(72 * 3600, $job->uniqueFor());
    }

    private function runMaster(array $unidades): void
    {
        $this->handleMaster(new SyncPcaDataJob(new PcaSyncParamsDTO(ano: 2026, cnpj: self::CNPJ)), $unidades);
    }

    private function handleMaster(SyncPcaDataJob $job, array $unidades): void
    {
        $client = Mockery::mock(PncpClient::class);
        $client->shouldReceive('getPcaUnidades')->once()->andReturn($unidades);

        $job->handle(
            $client,
            $this->app->make(PncpCircuitBreaker::class),
            $this->app->make(PncpFailureHandler::class),
            $this->app->make(SyncPcaPlanAction::class),
            $this->app->make(OrgaoRepository::class),
            $this->app->make(PcaPlanRepository::class),
            $this->app->make(PncpPcaCicloRepository::class),
        );
    }

    private function restoreWithoutSyncId(SyncPcaDataJob $job): SyncPcaDataJob
    {
        $payload = $job->__serialize();
        unset($payload["\0*\0syncId"]);

        /** @var SyncPcaDataJob $restored */
        $restored = (new \ReflectionClass($job))->newInstanceWithoutConstructor();
        $restored->__unserialize($payload);

        return $restored;
    }

    private function unidade(int $sequencial, CarbonInterface $dataAtualizacao): object
    {
        return (object) [
            'sequencialPca' => $sequencial,
            'codigoUnidade' => (string) (100 + $sequencial),
            'nomeUnidade'   => "Unidade {$sequencial}",
            'quantidade'    => 10,
            // Formato do PNCP: sem fuso.
            'dataAtualizacao' => $dataAtualizacao->format('Y-m-d\TH:i:s'),
        ];
    }

    private function plan(int $sequencial, ?CarbonInterface $syncedAt): PcaPlan
    {
        return PcaPlan::create([
            'cnpj_orgao'             => self::CNPJ,
            'ano_pca'                => 2026,
            'sequencial_pca'         => $sequencial,
            'itens_sincronizados_em' => $syncedAt,
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
