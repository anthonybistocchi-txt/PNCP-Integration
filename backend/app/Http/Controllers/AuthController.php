<?php

namespace App\Http\Controllers;

use App\Actions\User\CreateUserAction;
use App\Http\Requests\AuthRequest;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(
        private CreateUserAction $createUserAction
    ) {}

    public function register(AuthRequest $request): JsonResponse
    {
        $user = $this->createUserAction->execute($request->toDTO());

        return response()->json([
            'message' => 'Usuário criado com sucesso',
            'api_key' => $user->api_key,
        ], 201);
    }
}
