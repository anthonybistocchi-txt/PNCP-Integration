<?php

namespace App\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class IntegrationExceptionHandler
{
    /**
     * Report the exception.
     * Return false to prevent Laravel's default stack trace logging.
     */
    public function __invoke(Throwable $e): ?bool
    {
        if ($e instanceof ConnectionException) {
            Log::error(json_encode([
                'error_type'  => 'PNCP_TIMEOUT',
                'status_code' => 408,
                'reason'      => 'A API demorou muito para responder ou a conexão falhou (Timeout).',
                'message'     => $e->getMessage() // O próprio Laravel já injeta a URL e os detalhes do cURL na string do message
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return false;
        }

        if ($e instanceof RequestException) {
            $response = $e->response;
            
            $status = $response->status();
            $url    = (string) $response->effectiveUri();
            $body   = $response->body();
            
            // Se o PNCP retornar HTML (ex: página de erro 503 do Nginx/Cloudflare)
            if (str_starts_with(trim(strtolower($body)), '<html')) {
                $reason = "O Servidor do PNCP retornou uma página HTML de erro (provável instabilidade na infraestrutura deles).";
            } else {
                // Tenta decodificar se for JSON
                $decoded = json_decode($body, true);
                $reason = $decoded ?? $body;
            }

            Log::error(json_encode([
                'error_type'  => 'PNCP_HTTP_ERROR',
                'status_code' => $status,
                'url'         => $url,
                'reason'      => $reason,
                'response'    => [
                    'headers' => $response->headers(),
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            
            return false;
        }
        
        // Retornar nulo permite que o Laravel continue lidando com outras exceções normalmente
        return null;
    }
}
