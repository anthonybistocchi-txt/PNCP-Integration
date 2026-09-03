<?php

namespace Tests\Feature\Jobs\PublicContratations;

use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Jobs\PublicContratations\ProcessPncpPageJob;
use App\Jobs\PublicContratations\SyncPncpDataJob;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

class SyncPncpDataJobTest extends TestCase
{
    private const URL_LISTAGEM = 'https://pncp.gov.br/api/consulta/v1/contratacoes/atualizacao*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pncp.throttle.max_per_second'        => 0,
            'pncp.http.retries'                   => 2,
            'pncp.http.retry_sleep_ms'            => 3000,
            'pncp.breaker.server_error_threshold' => 3,
        ]);

        Sleep::fake();
        Queue::fake();
    }

    public function test_nao_toca_na_api_quando_o_breaker_esta_aberto(): void
    {
        Http::fake();

        app(PncpCircuitBreaker::class)->recordRateLimit(120);

        $delay = $this->executar();

        Http::assertNothingSent();
        $this->assertNotNull($delay, 'O job deveria ter sido devolvido para a fila.');
        $this->assertGreaterThanOrEqual(120, $delay);
    }

    /**
     * Uma falha isolada não abre a pausa global, mas o job precisa voltar para
     * a fila em vez de queimar uma tentativa e sujar o log com stack trace.
     */
    public function test_erro_500_reagenda_sem_abrir_a_pausa_global(): void
    {
        Http::fake([self::URL_LISTAGEM => Http::response('erro', 500)]);

        $delay = $this->executar();

        $this->assertNotNull($delay);
        $this->assertGreaterThanOrEqual(30, $delay);
        $this->assertLessThanOrEqual(90, $delay);
        $this->assertFalse(app(PncpCircuitBreaker::class)->isOpen());
    }

    public function test_despacha_um_job_por_pagina_e_fecha_o_breaker(): void
    {
        // Deixa o breaker meio aberto para provar que o sucesso o encerra.
        $breaker = app(PncpCircuitBreaker::class);
        $breaker->recordRateLimit(1);
        $this->travel(2)->seconds();
        $this->assertTrue($breaker->isHalfOpen());

        Http::fake([
            self::URL_LISTAGEM => Http::response(json_encode([
                'data'           => [['numeroControlePNCP' => 'x']],
                'totalPaginas'   => 3,
                'totalRegistros' => 120,
            ]), 200),
        ]);

        $this->executar();

        Queue::assertPushed(ProcessPncpPageJob::class, 3);
        $this->assertFalse($breaker->isHalfOpen());
        $this->assertFalse($breaker->isOpen());
    }

    public function test_resposta_sem_registros_nao_despacha_nada(): void
    {
        Http::fake([self::URL_LISTAGEM => Http::response('', 204)]);

        $delay = $this->executar();

        $this->assertNull($delay, 'Sem registros não é falha: o job deve terminar.');
        Queue::assertNothingPushed();
    }

    /**
     * 400 é parâmetro errado nosso. Precisa falhar de vez, não ficar em loop
     * de reagendamento até o retryUntil expirar.
     */
    public function test_erro_400_falha_permanentemente(): void
    {
        Http::fake([self::URL_LISTAGEM => Http::response('parametro invalido', 400)]);

        $falhou = false;

        $queueJob = $this->fakeQueueJob(onFail: function () use (&$falhou) {
            $falhou = true;
        });

        $this->novoJob()->setJob($queueJob)->handle(...$this->dependencias());

        $this->assertTrue($falhou, 'Esperava falha permanente.');
        $this->assertFalse(app(PncpCircuitBreaker::class)->isOpen());
    }

    /**
     * Executa o job e devolve o atraso do release, ou null se não houve.
     */
    private function executar(): ?int
    {
        $delay = null;

        $queueJob = $this->fakeQueueJob(onRelease: function (int $seconds) use (&$delay) {
            $delay = $seconds;
        });

        $this->novoJob()->setJob($queueJob)->handle(...$this->dependencias());

        return $delay;
    }

    private function novoJob(): SyncPncpDataJob
    {
        return new SyncPncpDataJob(new ListPublicContratationsDTO(
            dataInicial: '20260901',
            dataFinal: '20260902',
            codigoModalidadeContratacao: 6,
        ));
    }

    private function dependencias(): array
    {
        return [
            app(\App\Integrations\PNCP\PncpClient::class),
            app(PncpCircuitBreaker::class),
            app(\App\Support\ErrorHandling\PncpFailureHandler::class),
            app(\App\Integrations\PNCP\PncpSyncCycleTracker::class),
        ];
    }

    private function fakeQueueJob(?callable $onRelease = null, ?callable $onFail = null): QueueJobContract
    {
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('getJobId')->andReturn('test-job');
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('uuid')->andReturn('test-uuid');
        $queueJob->shouldReceive('hasFailed')->andReturn(false);
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('release')->andReturnUsing(function ($seconds = 0) use ($onRelease) {
            if ($onRelease) {
                $onRelease((int) $seconds);
            }
        });
        $queueJob->shouldReceive('fail')->andReturnUsing(function ($e = null) use ($onFail) {
            if ($onFail) {
                $onFail($e);
            }
        });

        return $queueJob;
    }
}
