<?php

namespace App\Integrations\PNCP;

use App\Exceptions\Infrastructure\PncpApiException;
use App\Exceptions\Infrastructure\PncpRateLimitedException;
use App\Exceptions\Infrastructure\PncpUnavailableException;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class PncpClient
{
    private string $baseUrlPublicacao = 'https://pncp.gov.br/api/consulta/v1/contratacoes/publicacao';
    private string $baseUrlAtualizacao = 'https://pncp.gov.br/api/consulta/v1/contratacoes/atualizacao';

    private string $baseUrlDocumentos = 'https://pncp.gov.br/api/pncp/v1/orgaos';

    /**
     * Busca licitações no PNCP com base nos filtros.
     *
     * Retorna null apenas quando o PNCP responde 204 (sem registros no
     * intervalo). Qualquer falha vira exceção — devolver null em erro faria o
     * job registrar "No records found" como sucesso e perder o dia inteiro.
     *
     * @throws PncpRateLimitedException  429
     * @throws PncpUnavailableException  5xx ou timeout
     * @throws \Illuminate\Http\Client\RequestException  4xx (erro nosso)
     */
    public function getContratacoes(array $queryParams = [], bool $porAtualizacao = true): ?object
    {
        $url = $porAtualizacao ? $this->baseUrlAtualizacao : $this->baseUrlPublicacao;

        $response = $this->request($url, $queryParams, (int) config('pncp.http.timeout'));

        if ($response->successful()) {
            return json_decode($response->body());
        }

        $this->throwForStatus($response);
    }

    /**
     * Busca os arquivos/anexos (Editais, Termos) de uma contratação específica.
     *
     * 404 é o caso normal de compra sem anexo e devolve lista vazia. Falha de
     * infraestrutura propaga para a página inteira ser reprocessada depois,
     * em vez de gravar `documentos: []` e perder os anexos em silêncio.
     */
    public function getArquivos(string $cnpj, int $ano, int $sequencial): array
    {
        $url = $this->baseUrlDocumentos . "/{$cnpj}/compras/{$ano}/{$sequencial}/arquivos";

        $response = $this->request($url, [], (int) config('pncp.http.document_timeout'));

        if ($response->successful()) {
            $arquivos = json_decode($response->body(), true);

            return is_array($arquivos) ? $arquivos : [];
        }

        if ($response->notFound()) {
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

        if ($minInterval <= 0) {
            return $this->send($url, $query, $timeout);
        }

        return Redis::throttle('pncp:global')
            ->allow(1)
            ->every($minInterval)
            ->block((int) config('pncp.throttle.block_seconds'))
            ->then(
                fn () => $this->send($url, $query, $timeout),
                fn () => throw new PncpUnavailableException(
                    message: 'Sem vaga no limitador de taxa do PNCP dentro do tempo de espera.',
                    statusCode: 429
                )
            );
    }

    private function send(string $url, array $query, int $timeout): Response
    {
        try {
            return Http::connectTimeout((int) config('pncp.http.connect_timeout'))
                ->timeout($timeout)
                ->retry(
                    (int) config('pncp.http.retries'),
                    (int) config('pncp.http.retry_sleep_ms'),
                    throw: false
                )
                ->withHeaders(['Accept' => 'application/json'])
                ->get($url, $query);
        } catch (ConnectionException $error) {
            // cURL 28 e afins: a sobrecarga do PNCP é tão grande que a resposta
            // sequer chega. Mesmo sintoma de um 5xx, mesmo tratamento.
            throw new PncpUnavailableException(
                message: 'Timeout ou falha de conexão com o PNCP: ' . $error->getMessage(),
                statusCode: 408,
                previous: $error
            );
        }
    }

    /**
     * @throws PncpRateLimitedException
     * @throws PncpUnavailableException
     * @throws \Illuminate\Http\Client\RequestException
     */
    private function throwForStatus(Response $response): never
    {
        if ($response->status() === 429) {
            throw new PncpRateLimitedException(
                retryAfter: $this->parseRetryAfter($response->header('Retry-After')),
                response: $response
            );
        }

        if ($response->serverError()) {
            throw new PncpUnavailableException(
                message: 'PNCP respondeu ' . $response->status() . ': ' . str($response->body())->limit(200),
                statusCode: $response->status(),
                response: $response
            );
        }

        // 400/401/403/404: parâmetro ou credencial errada do nosso lado.
        $response->throw();

        // Inalcançável: throw() só não lança em resposta bem-sucedida, e essas
        // não chegam aqui. Falhar alto é melhor que devolver null em silêncio.
        throw new PncpApiException(
            message: 'Resposta inesperada do PNCP com status ' . $response->status(),
            statusCode: $response->status(),
            response: $response
        );
    }

    /**
     * O header Retry-After pode vir em segundos ou como data HTTP.
     */
    private function parseRetryAfter(?string $header): ?int
    {
        if (blank($header)) {
            return null;
        }

        if (is_numeric($header)) {
            return max(0, (int) $header);
        }

        try {
            return max(0, now()->diffInSeconds(Carbon::parse($header), false));
        } catch (\Throwable) {
            return null;
        }
    }
}
