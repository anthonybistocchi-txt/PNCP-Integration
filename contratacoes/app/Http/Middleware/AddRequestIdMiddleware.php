<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddRequestIdMiddleware
{
    /**
     * Gera ou propaga um X-Request-ID único para cada requisição.
     *
     * O Request ID é adicionado ao contexto global do Log, garantindo que
     * todos os logs gerados durante o ciclo de vida da requisição (Controller,
     * Service, Repository) contenham o mesmo identificador, facilitando o
     * rastreamento no DataDog, Papertrail ou qualquer agregador de logs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();

        $request->headers->set('X-Request-ID', $requestId);

        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
