<?php

namespace Tests\Unit\Integrations\PNCP;

use App\Integrations\PNCP\PncpCircuitBreaker;
use Tests\TestCase;

class PncpCircuitBreakerTest extends TestCase
{
    private PncpCircuitBreaker $breaker;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pncp.breaker.server_error_threshold' => 3,
            'pncp.breaker.server_error_backoff'   => [1, 3, 10, 20],
            'pncp.breaker.rate_limit_backoff'     => [2, 5, 10, 20],
            'pncp.breaker.probe_lock_seconds'     => 120,
        ]);

        $this->breaker = new PncpCircuitBreaker();
    }

    public function test_falha_isolada_de_servidor_nao_abre_o_breaker(): void
    {
        $this->assertFalse($this->breaker->recordServerFailure());
        $this->assertFalse($this->breaker->recordServerFailure());

        $this->assertFalse($this->breaker->isOpen());
        $this->assertSame(2, $this->breaker->consecutiveFailures());
    }

    public function test_terceira_falha_consecutiva_abre_o_breaker_no_primeiro_degrau(): void
    {
        $this->breaker->recordServerFailure();
        $this->breaker->recordServerFailure();

        $this->assertTrue($this->breaker->recordServerFailure());
        $this->assertTrue($this->breaker->isOpen());

        // Primeiro degrau da escala: 1 minuto.
        $this->assertEqualsWithDelta(60, $this->breaker->remainingSeconds(), 2);
    }

    public function test_sucesso_zera_o_contador_de_falhas_consecutivas(): void
    {
        $this->breaker->recordServerFailure();
        $this->breaker->recordServerFailure();

        $this->breaker->recordSuccess();

        $this->assertSame(0, $this->breaker->consecutiveFailures());

        // Depois do sucesso, duas novas falhas ainda não podem abrir.
        $this->assertFalse($this->breaker->recordServerFailure());
        $this->assertFalse($this->breaker->recordServerFailure());
        $this->assertFalse($this->breaker->isOpen());
    }

    public function test_fica_meio_aberto_quando_a_pausa_vence(): void
    {
        $this->abrirPorFalhasDeServidor();

        $this->assertTrue($this->breaker->isOpen());
        $this->assertFalse($this->breaker->isHalfOpen());

        $this->travel(61)->seconds();

        $this->assertFalse($this->breaker->isOpen());
        $this->assertTrue($this->breaker->isHalfOpen());
    }

    public function test_sondagem_que_falha_reabre_no_proximo_degrau_sem_esperar_o_limiar(): void
    {
        $this->abrirPorFalhasDeServidor();
        $this->travel(61)->seconds();

        // Uma única falha basta: já sabemos que a API estava ruim.
        $this->assertTrue($this->breaker->recordServerFailure());

        // Segundo degrau da escala: 3 minutos.
        $this->assertEqualsWithDelta(180, $this->breaker->remainingSeconds(), 2);
    }

    public function test_sucesso_na_sondagem_fecha_o_breaker(): void
    {
        $this->abrirPorFalhasDeServidor();
        $this->travel(61)->seconds();

        $this->breaker->recordSuccess();

        $this->assertFalse($this->breaker->isOpen());
        $this->assertFalse($this->breaker->isHalfOpen());
    }

    public function test_rate_limit_abre_na_primeira_ocorrencia(): void
    {
        $this->assertTrue($this->breaker->recordRateLimit());
        $this->assertTrue($this->breaker->isOpen());

        // Primeiro degrau da escala de 429: 2 minutos.
        $this->assertEqualsWithDelta(120, $this->breaker->remainingSeconds(), 2);
    }

    public function test_rate_limit_respeita_o_retry_after_da_api(): void
    {
        $this->breaker->recordRateLimit(90);

        $this->assertEqualsWithDelta(90, $this->breaker->remainingSeconds(), 2);
    }

    /**
     * O degrau precisa avançar mesmo quando a duração vem do header, senão a
     * pausa vence sem meio aberto e todos os jobs voltam de uma vez.
     */
    public function test_pausa_vinda_do_retry_after_tambem_gera_meio_aberto(): void
    {
        $this->breaker->recordRateLimit(30);

        $this->travel(31)->seconds();

        $this->assertTrue($this->breaker->isHalfOpen());
    }

    public function test_429_repetido_escalona_quando_a_api_nao_manda_header(): void
    {
        $this->breaker->recordRateLimit();
        $this->assertEqualsWithDelta(120, $this->breaker->remainingSeconds(), 2);

        $this->travel(121)->seconds();

        $this->breaker->recordRateLimit();
        $this->assertEqualsWithDelta(300, $this->breaker->remainingSeconds(), 2);
    }

    public function test_apenas_um_job_consegue_a_sondagem(): void
    {
        $this->assertTrue($this->breaker->acquireProbe());
        $this->assertFalse($this->breaker->acquireProbe());
    }

    public function test_reabrir_libera_o_lock_para_a_proxima_sondagem(): void
    {
        $this->abrirPorFalhasDeServidor();
        $this->travel(61)->seconds();

        $this->assertTrue($this->breaker->acquireProbe());

        // A sondagem falhou e o breaker reabriu.
        $this->breaker->recordServerFailure();
        $this->travel(181)->seconds();

        // A próxima janela precisa de uma sondagem nova.
        $this->assertTrue($this->breaker->acquireProbe());
    }

    private function abrirPorFalhasDeServidor(): void
    {
        $this->breaker->recordServerFailure();
        $this->breaker->recordServerFailure();
        $this->breaker->recordServerFailure();
    }
}
