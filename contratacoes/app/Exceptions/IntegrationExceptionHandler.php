<?php

namespace App\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

class IntegrationExceptionHandler
{
    /**
     * Intercepta exceções antes do log padrão do Laravel.
     * Retornar false impede o stack trace gigante do framework.
     */
    public function __invoke(Throwable $e): ?bool
    {
        // 1. Timeout de conexão (cURL error 28)
        if ($e instanceof ConnectionException)
        {
            Log::error('PNCP_TIMEOUT', [
                'status_code' => 408,
                'reason'      => 'A API demorou muito para responder ou a conexão falhou (Timeout).',
                'message'     => $e->getMessage(),
            ]);

            return false;
        }

        // 2. Erros HTTP da API do PNCP (503, 500, 429, etc)
        if ($e instanceof RequestException)
        {
            $response = $e->response;

            $status = $response->status();
            $url    = (string) $response->effectiveUri();
            $body   = $response->body();

            // Se o PNCP retornar HTML (ex: página de erro 503 do Nginx/Cloudflare)
            if (str_starts_with(trim(strtolower($body)), '<html'))
            {
                $reason = "O Servidor do PNCP retornou uma página HTML de erro (provável instabilidade na infraestrutura deles).";
            }
            else
            {
                $decoded = json_decode($body, true);
                $reason  = $decoded ?? $body;
            }

            Log::error('PNCP_HTTP_ERROR', [
                'status_code' => $status,
                'url'         => $url,
                'reason'      => $reason,
                'response'    => [
                    'headers' => $response->headers(),
                ],
            ]);

            return false;
        }

        // 3. Job esgotou todas as tentativas e foi descartado da fila
        if ($e instanceof MaxAttemptsExceededException)
        {
            Log::error('JOB_MAX_ATTEMPTS_EXCEEDED', [
                'reason' => 'O Job foi descartado permanentemente após esgotar todas as tentativas configuradas.',
                'job'    => $e->getMessage(),
            ]);

            return false;
        }

        // Para qualquer outra exceção, o Laravel continua com o comportamento padrão
        return null;
    }
}
