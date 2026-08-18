<?php

namespace App\Http\Middleware;

use App\Models\User;
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

        if (
            $request->header('Authorization') !== env('ADMIN_API_KEY')
            &&
            $request->header('Authorization') !== $user->api_key
        ) {
            return response()->json([
                'error' => 'Você não tem permissão para criar usuários',
            ], 401);
        }



        return $next($request);
    }
}
