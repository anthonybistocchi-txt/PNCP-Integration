<?php

namespace App\Exceptions\Infrastructure;

use Illuminate\Http\Client\Response;

/**
 * O PNCP respondeu 429. É a API pedindo explicitamente para parar, então o
 * circuit breaker abre de imediato — sem esperar falhas consecutivas.
 *
 * $retryAfter vem do header Retry-After quando o PNCP o envia. Quando é null,
 * cabe ao circuit breaker escolher a pausa pela escala de escalonamento.
 */
class PncpRateLimitedException extends PncpApiException
{
    public function __construct(
        public ?int $retryAfter = null,
        ?Response $response = null,
        ?\Throwable $previous = null
    )
    {
        parent::__construct(
            message: $retryAfter !== null
                ? "PNCP respondeu 429 e pediu {$retryAfter}s de espera."
                : 'PNCP respondeu 429 (Too Many Requests) sem header Retry-After.',
            statusCode: 429,
            response:   $response,
            previous:   $previous
        );
    }
}
