<?php

namespace App\Http\Controllers\Orgaos;

use App\Actions\Orgaos\GetOrgaoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orgaos\GetOrgaoRequest;
use App\Resources\Orgaos\OrgaoDetailResource;
use Illuminate\Http\JsonResponse;

class GetOrgaoController extends Controller
{
    public function __construct(
        private GetOrgaoAction $action
    ) {}

    public function show(GetOrgaoRequest $request): JsonResponse
    {
        $orgao = $this->action->show($request->toDTO());

        return response()->json([
            'data' => new OrgaoDetailResource($orgao),
        ], 200);
    }
}
