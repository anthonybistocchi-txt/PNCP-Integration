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
        if (!$request->header('Authorization')) {
            return response()->json([
                'error' => 'API KEY não fornecida',
            ], 401);
        }

        $user = User::where('api_key', $request->header('Authorization'))->first();

        if (!$user) {
            return response()->json([
                'error' => 'API KEY inválida',
            ], 401);
        }

        return $next($request);
    }
}
