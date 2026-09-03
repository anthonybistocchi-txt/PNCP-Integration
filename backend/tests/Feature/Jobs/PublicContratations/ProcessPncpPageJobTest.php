<?php

namespace Tests\Feature\Jobs\PublicContratations;

use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Jobs\PublicContratations\ProcessPncpPageJob;
use App\Models\PublicContratations\PublicContratation;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

class ProcessPncpPageJobTest extends TestCase
{
    use RefreshDatabase;

    private const URL_LISTAGEM   = 'https://pncp.gov.br/api/consulta/v1/contratacoes/atualizacao*';
    private const URL_DOCUMENTOS = 'https://pncp.gov.br/api/pncp/v1/orgaos/*';

    private string $syncId = 'ciclo-de-teste';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pncp.throttle.max_per_second'        => 0,
            'pncp.http.retries'                   => 2,
            'pncp.http.retry_sleep_ms'            => 3000,
            'pncp.document_delay_ms'              => 0,
            'pncp.breaker.server_error_threshold' => 3,
        ]);

        Sleep::fake();

        // Ciclo de 2 páginas: dá para ver o contador avançar sem fechar.
        app(PncpSyncCycleTracker::class)->start($this->syncId, 2);
    }

    public function test_pagina_processada_grava_os_registros_e_avanca_o_ciclo(): void
    {
        Http::fake([
            self::URL_LISTAGEM   => Http::response(json_encode([
                'data'           => [$this->itemDoPncp()],
                'totalPaginas'   => 2,
                'totalRegistros' => 2,
            ]), 200),
            self::URL_DOCUMENTOS => Http::response(json_encode([['titulo' => 'Edital']]), 200),
        ]);

        $this->executar();

        $contratacao = PublicContratation::query()->firstOrFail();
        $this->assertSame('06000.000001/2026-01', $contratacao->numero_controle_pncp);
        $this->assertSame([['titulo' => 'Edital']], $contratacao->documentos);

        $this->assertSame(1, $this->paginasConcluidas());
    }

    public function test_pagina_sem_dados_avanca_o_ciclo(): void
    {
        Http::fake([self::URL_LISTAGEM => Http::response('', 204)]);

        $delay = $this->executar();

        $this->assertNull($delay);
        $this->assertSame(1, $this->paginasConcluidas());
    }

    /**
     * A página reagendada ainda vai rodar: se o contador avançasse aqui, o
     * SYNC_CYCLE_FINISHED seria emitido com páginas faltando.
     */
    public function test_reagendamento_por_indisponibilidade_nao_avanca_o_ciclo(): void
    {
        Http::fake([self::URL_LISTAGEM => Http::response('erro', 503)]);

        $delay = $this->executar();

        $this->assertNotNull($delay);
        $this->assertSame(0, $this->paginasConcluidas());
    }

    public function test_breaker_aberto_devolve_a_pagina_sem_tocar_na_api(): void
    {
        Http::fake();

        app(PncpCircuitBreaker::class)->recordRateLimit(120);

        $delay = $this->executar();

        Http::assertNothingSent();
        $this->assertGreaterThanOrEqual(120, $delay);
        $this->assertSame(0, $this->paginasConcluidas());
    }

    /**
     * Anexo indisponível reagenda a página inteira em vez de gravar
     * `documentos: []` e perder os arquivos em silêncio.
     */
    public function test_falha_ao_buscar_anexos_nao_grava_registro_incompleto(): void
    {
        Http::fake([
            self::URL_LISTAGEM   => Http::response(json_encode([
                'data'           => [$this->itemDoPncp()],
                'totalPaginas'   => 2,
                'totalRegistros' => 2,
            ]), 200),
            self::URL_DOCUMENTOS => Http::response('erro', 503),
        ]);

        $delay = $this->executar();

        $this->assertNotNull($delay);
        $this->assertSame(0, PublicContratation::query()->count());
        $this->assertSame(0, $this->paginasConcluidas());
    }

    private function executar(): ?int
    {
        $delay = null;

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('getJobId')->andReturn('test-job');
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('uuid')->andReturn('test-uuid');
        $queueJob->shouldReceive('hasFailed')->andReturn(false);
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('fail')->andReturnNull();
        $queueJob->shouldReceive('release')->andReturnUsing(function ($seconds = 0) use (&$delay) {
            $delay = (int) $seconds;
        });

        $job = new ProcessPncpPageJob(
            new ListPublicContratationsDTO(
                dataInicial: '20260901',
                dataFinal: '20260902',
                codigoModalidadeContratacao: 6,
            ),
            pagina: 1,
            tamanhoPagina: 50,
            syncId: $this->syncId,
            cycleStartedAt: now()->toIso8601String(),
        );

        $job->setJob($queueJob)->handle(
            app(\App\Integrations\PNCP\PncpClient::class),
            app(\App\Actions\PublicContratations\SyncPublicContratationAction::class),
            app(PncpCircuitBreaker::class),
            app(\App\Support\ErrorHandling\PncpFailureHandler::class),
            app(PncpSyncCycleTracker::class),
        );

        return $delay;
    }

    private function paginasConcluidas(): int
    {
        return (int) Cache::get("pncp_sync:{$this->syncId}:done", 0);
    }

    private function itemDoPncp(): array
    {
        return [
            'numeroControlePNCP'     => '06000.000001/2026-01',
            'orgaoEntidade'          => ['cnpj' => '06000000000001'],
            'anoCompra'              => 2026,
            'sequencialCompra'       => 1,
            'situacaoCompraId'       => 1,
            'valorTotalEstimado'     => 1500.55,
            'dataAberturaProposta'   => '2026-09-01T10:00:00',
            'dataEncerramentoProposta' => '2026-09-15T18:00:00',
            'dataPublicacaoPncp'     => '2026-09-01T08:00:00',
            'dataAtualizacaoGlobal'  => '2026-09-02T08:00:00',
            'unidadeOrgao'           => ['ufSigla' => 'SP', 'codigoIbge' => 3550308],
            'modalidadeId'           => 6,
            'srp'                    => false,
            'objetoCompra'           => 'Aquisição de material de escritório',
        ];
    }
}
