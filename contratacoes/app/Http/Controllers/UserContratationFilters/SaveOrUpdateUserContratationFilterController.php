<?php

namespace App\Http\Controllers\UserContratationFilters;

use App\Actions\UserContratationFilters\SaveOrUpdateUserContratationFilterAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserContratationFilters\SaveUserContratationFilterRequest;
use App\Resources\UserContratationFilters\UserContratationFilterResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SaveOrUpdateUserContratationFilterController extends Controller
{
    public function __construct(
        private SaveOrUpdateUserContratationFilterAction $action
    ) {}

    public function saveOrUpdate(SaveUserContratationFilterRequest $request): JsonResponse
    {
        $filterToSave = $request->toDTO(Auth::id());
        $savedFilters = $this->action->saveOrUpdate($filterToSave);

        return response()->json([
            'message' => 'Filtro cadastrado com sucesso.',
            'data'    => UserContratationFilterResource::collection($savedFilters),
        ], 201);
    }
}
