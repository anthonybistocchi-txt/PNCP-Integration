<?php

namespace App\Http\Middleware;

use App\Models\Auth\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPermissionToCreateUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = User::where('id', 1)->first();

        $token = $request->bearerToken() ?? $request->header('API_KEY') ?? $request->header('Authorization');
        $token = str_replace('Bearer ', '', (string) $token);

        if (
            $token !== env('ADMIN_API_KEY')
            &&
            $token !== $user->api_key
        ) {
            return response()->json([
                'error'      => 'forbidden',
                'message'    => 'Você não tem permissão para criar usuários',
                'details'    => (object) [],
                'request_id' => $request->header('X-Request-ID', 'unknown'),
            ], 403);
        }



        return $next($request);
    }
}
