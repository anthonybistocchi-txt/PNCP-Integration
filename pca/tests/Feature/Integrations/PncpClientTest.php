<?php

namespace Tests\Feature\Integrations;

use App\Integrations\PNCP\PncpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PncpClientTest extends TestCase
{
    private const CNPJ = '12345678000199';

    public function test_unidades_uses_the_configured_page_size()
    {
        config(['pncp.pca.unidades_page_size' => 500]);
        Http::fake(['*' => Http::response([['sequencialPca' => 1]], 200)]);

        $unidades = (new PncpClient())->getPcaUnidades(self::CNPJ, 2026);

        $this->assertCount(1, $unidades);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'tamanhoPagina=500'));
    }

    public function test_unidades_stops_on_a_short_page_without_another_request()
    {
        config(['pncp.pca.unidades_page_size' => 2]);
        Http::fake(['*' => Http::sequence()
            ->push([['sequencialPca' => 1], ['sequencialPca' => 2]], 200)
            ->push([['sequencialPca' => 3]], 200)]);

        $unidades = (new PncpClient())->getPcaUnidades(self::CNPJ, 2026);

        $this->assertSame([1, 2, 3], array_map(fn ($u) => $u->sequencialPca, $unidades));
        Http::assertSentCount(2);
    }

    public function test_unidades_treats_a_404_after_a_full_page_as_the_end_of_the_list()
    {
        // O PNCP responde 404 para a página além da última (testado em 05/10
        // com 906 unidades e tamanhoPagina=500: a página 3 devolve 404).
        config(['pncp.pca.unidades_page_size' => 2]);
        Http::fake(['*' => Http::sequence()
            ->push([['sequencialPca' => 1], ['sequencialPca' => 2]], 200)
            ->push(['status' => '404'], 404)]);

        $unidades = (new PncpClient())->getPcaUnidades(self::CNPJ, 2026);

        $this->assertSame([1, 2], array_map(fn ($u) => $u->sequencialPca, $unidades));
        Http::assertSentCount(2);
    }

    public function test_unidades_returns_empty_when_the_organ_has_no_pca()
    {
        Http::fake(['*' => Http::response(['status' => '404'], 404)]);

        $this->assertSame([], (new PncpClient())->getPcaUnidades(self::CNPJ, 2026));
        Http::assertSentCount(1);
    }

    public function test_a_404_is_requested_only_once()
    {
        // Antes, o retry do Laravel repetia qualquer resposta não-2xx: um 404
        // custava duas requisições e 3s de sleep na mesma vaga do limitador.
        config(['pncp.http.retries' => 2, 'pncp.http.retry_sleep_ms' => 0]);
        Http::fake(['*' => Http::response(['status' => '404'], 404)]);

        (new PncpClient())->getPcaItensQuantidade(self::CNPJ, 2026, 1);

        Http::assertSentCount(1);
    }

    public function test_server_errors_are_still_retried()
    {
        config(['pncp.http.retries' => 2, 'pncp.http.retry_sleep_ms' => 0]);
        Http::fake(['*' => Http::sequence()
            ->push('boom', 500)
            ->push('27', 200)]);

        $this->assertSame(27, (new PncpClient())->getPcaItensQuantidade(self::CNPJ, 2026, 1));
        Http::assertSentCount(2);
    }

    public function test_other_client_errors_are_not_retried()
    {
        config(['pncp.http.retries' => 2, 'pncp.http.retry_sleep_ms' => 0]);
        Http::fake(['*' => Http::response(['status' => '400'], 400)]);

        try
        {
            (new PncpClient())->getPcaItensQuantidade(self::CNPJ, 2026, 1);
            $this->fail('400 deveria lançar exceção.');
        }
        catch (\Illuminate\Http\Client\RequestException)
        {
            // esperado: erro nosso, tratado como permanente pela JobExceptionPolicy
        }

        Http::assertSentCount(1);
    }

    public function test_items_quantity_distinguishes_404_from_zero()
    {
        Http::fake([
            '*/1/itens/quantidade' => Http::response('27', 200),
            '*/2/itens/quantidade' => Http::response('0', 200),
            '*/3/itens/quantidade' => Http::response(['status' => '404'], 404),
        ]);

        $client = new PncpClient();

        $this->assertSame(27, $client->getPcaItensQuantidade(self::CNPJ, 2026, 1));
        $this->assertSame(0, $client->getPcaItensQuantidade(self::CNPJ, 2026, 2));
        $this->assertNull($client->getPcaItensQuantidade(self::CNPJ, 2026, 3));
    }
}
