<?php

namespace App\Http\Middleware;

use App\Models\Auth\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->header('API_KEY') ?? $request->header('Authorization');

        if (!$token) {
            $this->logAuthFailure($request, 'API_KEY ausente');

            return response()->json([
                'error' => 'API KEY não fornecida',
            ], 401);
        }

        $token = str_replace(['Bearer ', '"', "'"], '', $token);
        $token = trim($token);

        $user = User::where('api_key', $token)->first();

        if (!$user) 
        {
            $this->logAuthFailure($request, 'API_KEY inválida');

            return response()->json([
                'error' => 'API KEY inválida',
            ], 401);
        }

        if (!$user->isActive()) 
        {
            $this->logAuthFailure($request, 'API_KEY inativa');

            return response()->json([
                'error' => 'API KEY inativa',
            ], 401);
        }

        // Injeta o usuário autenticado no container do Laravel.
        // Permite usar auth()->user() em qualquer ponto downstream (Controller, Service, logs).
        Auth::setUser($user);

        return $next($request);
    }

    /**
     * Registra tentativas de autenticação inválidas para auditoria e detecção de ataques.
     * Não loga os headers de Authorization para evitar vazamento da chave tentada.
     */
    private function logAuthFailure(Request $request, string $reason): void
    {
        Log::warning(json_encode([
            'event'    => 'AUTH_FAILED',
            'reason'   => $reason,
            'ip'       => $request->ip(),
            'endpoint' => $request->path(),
            'method'   => $request->method(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
