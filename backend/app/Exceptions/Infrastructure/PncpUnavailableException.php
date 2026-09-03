<?php

namespace App\Exceptions\Infrastructure;

use Illuminate\Http\Client\Response;

/**
 * Infraestrutura do PNCP indisponível: 5xx (tipicamente estouro do pool de
 * conexões deles) ou timeout da nossa requisição.
 *
 * Falha isolada não pausa nada, porque a API é instável por requisição. O
 * circuit breaker só abre depois de N falhas consecutivas.
 */
class PncpUnavailableException extends PncpApiException
{
    public function __construct(
        string $message = 'Infraestrutura do PNCP indisponível.',
        int $statusCode = 503,
        ?Response $response = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            statusCode: $statusCode,
            response: $response,
            previous: $previous
        );
    }
}
