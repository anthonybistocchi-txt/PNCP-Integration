<?php

namespace App\Http\Controllers\UserContratationFilters;

use App\Actions\UserContratationFilters\DeleteUserContratationFilterAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DeleteUserContratationFilterController extends Controller
{
    public function __construct(
        private DeleteUserContratationFilterAction $action
    ) {}

    public function delete(int $id): JsonResponse
    {
        $this->action->delete($id, Auth::id());

        return response()->json([], 204);
    }
}
