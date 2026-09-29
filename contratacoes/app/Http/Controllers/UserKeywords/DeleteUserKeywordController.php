<?php

namespace App\Http\Controllers\UserKeywords;

use App\Actions\UserKeywords\DeleteUserKeywordAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DeleteUserKeywordController extends Controller
{
    public function __construct(
        private DeleteUserKeywordAction $action
    ) {}

    public function delete(int $id): JsonResponse
    {
        $this->action->delete($id, Auth::id());

        return response()->json([], 204);
    }
}
