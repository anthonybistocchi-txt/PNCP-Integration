<?php

namespace App\Http\Controllers\Auth;

use App\Actions\User\GetMeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MeRequest;
use App\Resources\Auth\UserResource;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function __construct(
        private GetMeAction $getMeAction
    ) {}

    public function me(MeRequest $request): JsonResponse
    {
        $user = $this->getMeAction->get($request->toDTO());

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }
}
