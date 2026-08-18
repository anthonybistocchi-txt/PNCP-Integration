<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
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
            return response()->json([
                'error' => 'API KEY não fornecida',
            ], 401);
        }

        $token = str_replace(['Bearer ', '"', "'"], '', $token);
        $token = trim($token);

        $user = User::where('api_key', $token)->first();

        if (!$user) {
            return response()->json([
                'error' => 'API KEY inválida',
            ], 401);
        }

        return $next($request);
    }
}
