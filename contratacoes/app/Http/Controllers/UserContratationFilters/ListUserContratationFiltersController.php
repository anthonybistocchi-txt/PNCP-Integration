<?php

namespace App\Http\Controllers\UserContratationFilters;

use App\Actions\UserContratationFilters\ListUserContratationFiltersAction;
use App\Http\Controllers\Controller;
use App\Resources\UserContratationFilters\UserContratationFilterResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ListUserContratationFiltersController extends Controller
{
    public function __construct(
        private ListUserContratationFiltersAction $action
    ) {}

    public function listByUserId(): JsonResponse
    {
        $filters = $this->action->listByUserId(Auth::id());

        return response()->json([
            'data' => UserContratationFilterResource::collection($filters),
        ]);
    }
}
