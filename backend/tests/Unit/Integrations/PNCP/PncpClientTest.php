<?php

namespace Tests\Unit\Integrations\PNCP;

use App\Exceptions\Infrastructure\PncpRateLimitedException;
use App\Exceptions\Infrastructure\PncpUnavailableException;
use App\Integrations\PNCP\PncpClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PncpClientTest extends TestCase
{
    private PncpClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Sem Redis nos testes: o limitador de taxa fica desligado.
            'pncp.throttle.max_per_second' => 0,
            'pncp.http.retries'            => 2,
            'pncp.http.retry_sleep_ms'     => 3000,
            'pncp.http.timeout'            => 40,
            'pncp.http.document_timeout'   => 10,
            'pncp.http.connect_timeout'    => 5,
        ]);

        Sleep::fake();

        $this->client = new PncpClient();
    }

    /**
     * O ponto central da refatoração: um 500 persistente custa 2 requisições,
     * não 3. Antes eram 3 requisições por tentativa de job, multiplicadas por
     * 5 tentativas e 10 modalidades.
     */
    public function test_erro_500_persistente_faz_apenas_duas_requisicoes(): void
    {
        Http::fake([
            '*' => Http::response('Failed to obtain JDBC Connection', 500),
        ]);

        try {
            $this->client->getContratacoes(['pagina' => 1]);
            $this->fail('Esperava PncpUnavailableException.');
        } catch (PncpUnavailableException $error) {
            $this->assertSame(500, $error->statusCode);
        }

        Http::assertSentCount(2);
    }

    public function test_sucesso_apos_falha_nao_desperdica_tentativas(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('erro', 500)
                ->push(json_encode(['data' => [], 'totalPaginas' => 1]), 200),
        ]);

        $resposta = $this->client->getContratacoes(['pagina' => 1]);

        $this->assertSame(1, $resposta->totalPaginas);
        Http::assertSentCount(2);
    }

    public function test_resposta_204_devolve_null_sem_lancar(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->assertNull($this->client->getContratacoes(['pagina' => 1]));
    }

    public function test_429_lanca_rate_limited_com_o_retry_after_da_api(): void
    {
        Http::fake([
            '*' => Http::response('too many requests', 429, ['Retry-After' => '90']),
        ]);

        try {
            $this->client->getContratacoes(['pagina' => 1]);
            $this->fail('Esperava PncpRateLimitedException.');
        } catch (PncpRateLimitedException $error) {
            $this->assertSame(90, $error->retryAfter);
        }
    }

    public function test_429_sem_header_deixa_a_escala_decidir(): void
    {
        Http::fake(['*' => Http::response('too many requests', 429)]);

        try {
            $this->client->getContratacoes(['pagina' => 1]);
            $this->fail('Esperava PncpRateLimitedException.');
        } catch (PncpRateLimitedException $error) {
            $this->assertNull($error->retryAfter);
        }
    }

    public function test_retry_after_em_formato_de_data_http(): void
    {
        Http::fake([
            '*' => Http::response('too many requests', 429, [
                'Retry-After' => now()->addSeconds(120)->toRfc7231String(),
            ]),
        ]);

        try {
            $this->client->getContratacoes(['pagina' => 1]);
            $this->fail('Esperava PncpRateLimitedException.');
        } catch (PncpRateLimitedException $error) {
            $this->assertEqualsWithDelta(120, $error->retryAfter, 2);
        }
    }

    /**
     * 4xx é erro nosso (parâmetro inválido), não indisponibilidade deles: não
     * pode virar pausa global nem ficar em loop de reagendamento.
     */
    public function test_erro_400_nao_vira_indisponibilidade(): void
    {
        Http::fake(['*' => Http::response('parametro invalido', 400)]);

        $this->expectException(RequestException::class);

        $this->client->getContratacoes(['pagina' => 1]);
    }

    public function test_arquivos_devolve_vazio_quando_a_compra_nao_tem_anexo(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertSame([], $this->client->getArquivos('123', 2026, 1));
    }

    /**
     * Antes esse caso gravava `documentos: []` e os anexos sumiam em silêncio.
     */
    public function test_arquivos_propaga_indisponibilidade_em_vez_de_perder_anexos(): void
    {
        Http::fake(['*' => Http::response('erro', 503)]);

        $this->expectException(PncpUnavailableException::class);

        $this->client->getArquivos('123', 2026, 1);
    }

    public function test_arquivos_devolve_a_lista_quando_existe(): void
    {
        Http::fake([
            '*' => Http::response(json_encode([['titulo' => 'Edital']]), 200),
        ]);

        $this->assertSame(
            [['titulo' => 'Edital']],
            $this->client->getArquivos('123', 2026, 1)
        );
    }
}
