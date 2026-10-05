<?php

namespace App\Integrations\PNCP;

use App\Exceptions\Infrastructure\PncpApiException;
use App\Exceptions\Infrastructure\PncpRateLimitedException;
use App\Exceptions\Infrastructure\PncpUnavailableException;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class PncpClient
{
    private string $baseUrlOrgaos = 'https://pncp.gov.br/api/pncp/v1/orgaos';

    /**
     * Busca as unidades de um órgão que possuem PCA no ano especificado.
     * O endpoint pagina (default 10); agregamos todas as páginas aqui.
     *
     * O PNCP responde 404 tanto para órgão sem PCA (já na página 1) quanto
     * para uma página além da última (testado em 05/10: 906 unidades com
     * tamanhoPagina=500 => página 3 devolve 404). Por isso 404 nunca é erro:
     * encerra a listagem e devolve o que já foi acumulado.
     *
     * O tamanho da página vem de `pncp.pca.unidades_page_size`. Com 50, um
     * órgão de 906 unidades custava 19 requisições dentro do timeout de 300s
     * do mestre (já houve execução de 283s); com 500 são 2, com conteúdo
     * idêntico.
     *
     * @return list<object>
     */
    public function getPcaUnidades(string $cnpj, int $ano): array
    {
        $url = $this->baseUrlOrgaos . "/{$cnpj}/pca/{$ano}/consolidado/unidades";
        $tamanhoPagina = max(1, (int) config('pncp.pca.unidades_page_size', 500));
        $pagina = 1;
        $todas = [];

        while (true)
        {
            $response = $this->request($url, [
                'pagina'        => $pagina,
                'tamanhoPagina' => $tamanhoPagina,
            ], (int) config('pncp.http.timeout'));

            if ($response->notFound())
            {
                return $todas;
            }

            if (! $response->successful())
            {
                $this->throwForStatus($response);
            }

            $chunk = json_decode($response->body());
            if (! is_array($chunk) || $chunk === [])
            {
                break;
            }

            foreach ($chunk as $item)
            {
                $todas[] = $item;
            }

            if (count($chunk) < $tamanhoPagina)
            {
                break;
            }

            $pagina++;
        }

        return $todas;
    }

    /**
     * Busca o resumo consolidado do PCA de um órgão no ano especificado.
     *
     * O endpoint devolve um objeto único (não uma lista); a lista de planos
     * por unidade vem de `getPcaUnidades()`.
     */
    public function getPcaConsolidado(string $cnpj, int $ano): ?object
    {
        $url = $this->baseUrlOrgaos . "/{$cnpj}/pca/{$ano}/consolidado";

        $response = $this->request($url, [], (int) config('pncp.http.timeout'));

        if ($response->successful())
        {
            $pca = json_decode($response->body());

            return is_object($pca) ? $pca : null;
        }

        if ($response->notFound())
        {
            return null;
        }

        $this->throwForStatus($response);
    }

    /**
     * Busca os itens de um PCA específico (por sequencial).
     *
     * O endpoint devolve um array simples, sem envelope de paginação — o
     * total de itens, quando necessário, vem de `getPcaItensQuantidade()`.
     *
     * @return list<object>
     */
    public function getPcaItens(string $cnpj, int $ano, int $sequencial, array $params = []): array
    {
        $url = $this->baseUrlOrgaos . "/{$cnpj}/pca/{$ano}/{$sequencial}/itens";

        $response = $this->request($url, $params, (int) config('pncp.http.timeout'));

        if ($response->successful())
        {
            $itens = json_decode($response->body());

            return is_array($itens) ? $itens : [];
        }

        if ($response->notFound())
        {
            return [];
        }

        $this->throwForStatus($response);
    }

    /**
     * Quantidade total de itens de um PCA.
     *
     * O endpoint de itens não devolve total no payload, então é esta chamada
     * que dimensiona a paginação do sync.
     *
     * `null` = 404 (o PNCP não conhece o plano), distinto de `0` (plano
     * existente sem itens). Quem chama não deve tratar 404 como "plano vazio".
     */
    public function getPcaItensQuantidade(string $cnpj, int $ano, int $sequencial): ?int
    {
        $url = $this->baseUrlOrgaos . "/{$cnpj}/pca/{$ano}/{$sequencial}/itens/quantidade";

        $response = $this->request($url, [], (int) config('pncp.http.timeout'));

        if ($response->successful())
        {
            return (int) json_decode($response->body());
        }

        if ($response->notFound())
        {
            return null;
        }

        $this->throwForStatus($response);
    }

    /**
     * Busca o valor por categoria de item de um PCA.
     *
     * @return list<object>
     */
    public function getPcaValorCategoria(string $cnpj, int $ano, int $sequencial): array
    {
        $url = $this->baseUrlOrgaos . "/{$cnpj}/pca/{$ano}/{$sequencial}/valorescategoriaitem";

        $response = $this->request($url, [], (int) config('pncp.http.timeout'));

        if ($response->successful())
        {
            $valores = json_decode($response->body());
            return is_array($valores) ? $valores : [];
        }

        if ($response->notFound())
        {
            return [];
        }

        $this->throwForStatus($response);
    }

    /**
     * Executa a requisição respeitando um intervalo mínimo entre chamadas ao
     * PNCP. O limite é compartilhado entre todos os workers e cobre os dois
     * endpoints (contratacoes e documentos), porque o WAF do PNCP demonstrou
     * disparar por rajada — mesmo poucas requisições por segundo, se
     * concentradas, já produziram 429/5xx em produção (05 e 06/09).
     *
     * Por isso este limitador é "1 vaga por janela de N segundos"
     * (`allow(1)->every($minInterval)`), e não "N vagas por segundo": a
     * segunda forma permite rajadas de N requisições quase simultâneas no
     * início de cada janela, que é exatamente o padrão que tem disparado o
     * circuit breaker.
     */
    private function request(string $url, array $query, int $timeout): Response
    {
        $minInterval = (int) config('pncp.throttle.min_interval_seconds');

        if ($minInterval <= 0)
        {
            return $this->send($url, $query, $timeout);
        }

        return Redis::throttle('pncp:global')
            ->allow(1)
            ->every($minInterval)
            ->block((int) config('pncp.throttle.block_seconds'))
            ->then(
                fn () => $this->send($url, $query, $timeout),
                fn () => throw new PncpUnavailableException(
                    message:    'Sem vaga no limitador de taxa do PNCP dentro do tempo de espera.',
                    statusCode: 429
                )
            );
    }

    private function send(string $url, array $query, int $timeout): Response
    {
        try
        {
            return Http::connectTimeout((int) config('pncp.http.connect_timeout'))
                ->timeout($timeout)
                ->retry(
                    (int) config('pncp.http.retries'),
                    (int) config('pncp.http.retry_sleep_ms'),
                    fn (\Throwable $exception) => $this->shouldRetry($exception),
                    throw: false
                )
                ->withHeaders(['Accept' => 'application/json'])
                ->get($url, $query);
        }
        catch (ConnectionException $error)
        {
            // cURL 28 e afins: a sobrecarga do PNCP é tão grande que a resposta
            // sequer chega. Mesmo sintoma de um 5xx, mesmo tratamento.
            throw new PncpUnavailableException(
                message:    'Timeout ou falha de conexão com o PNCP: ' . $error->getMessage(),
                statusCode: 408,
                previous:   $error
            );
        }
    }

    /**
     * Sem um critério, o `retry()` do Laravel repete QUALQUER resposta que não
     * seja 2xx: todo 404 (órgão sem PCA, página além da última) virava duas
     * requisições e 3s de sleep dentro de uma única vaga do limitador global.
     * 4xx é determinístico, repetir não muda o resultado.
     *
     * Repete falha de conexão/timeout, 5xx e 408 (o PNCP é instável por
     * requisição) e 429 (comportamento mantido; ver PncpFailureHandler).
     */
    private function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof RequestException)
        {
            $status = $exception->response->status();

            return $status >= 500 || $status === 408 || $status === 429;
        }

        return true;
    }

    /**
     * @throws PncpRateLimitedException
     * @throws PncpUnavailableException
     * @throws \Illuminate\Http\Client\RequestException
     */
    private function throwForStatus(Response $response): never
    {
        if ($response->status() === 429)
        {
            throw new PncpRateLimitedException(
                retryAfter: $this->parseRetryAfter($response->header('Retry-After')),
                response:   $response
            );
        }

        if ($response->serverError())
        {
            throw new PncpUnavailableException(
                message:    'PNCP respondeu ' . $response->status() . ': ' . str($response->body())->limit(200),
                statusCode: $response->status(),
                response:   $response
            );
        }

        // 400/401/403/404: parâmetro ou credencial errada do nosso lado.
        $response->throw();

        // Inalcançável: throw() só não lança em resposta bem-sucedida, e essas
        // não chegam aqui. Falhar alto é melhor que devolver null em silêncio.
        throw new PncpApiException(
            message:    'Resposta inesperada do PNCP com status ' . $response->status(),
            statusCode: $response->status(),
            response:   $response
        );
    }

    /**
     * O header Retry-After pode vir em segundos ou como data HTTP.
     */
    private function parseRetryAfter(?string $header): ?int
    {
        if (blank($header))
        {
            return null;
        }

        if (is_numeric($header))
        {
            return max(0, (int) $header);
        }

        try
        {
            return max(0, now()->diffInSeconds(Carbon::parse($header), false));
        }
        catch (\Throwable)
        {
            return null;
        }
    }
}
