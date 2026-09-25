<?php

namespace App\Http\Controllers\Auth;

use App\Actions\User\CreateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthRequest;
use App\Resources\Auth\UserResource;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(
        private CreateUserAction $createUserAction
    ) {}

    public function register(AuthRequest $request): JsonResponse
    {
        $user = $this->createUserAction->create($request->toDTO());

        return response()->json([
            'message' => 'Usuário criado com sucesso',
            'data'    => new UserResource($user),
        ], 201);
    }
}
