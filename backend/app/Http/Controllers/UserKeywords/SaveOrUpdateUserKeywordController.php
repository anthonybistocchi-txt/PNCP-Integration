<?php

namespace App\Http\Controllers\UserKeywords;

use App\Actions\UserKeywords\SaveOrUpdateUserKeywordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserKeywords\SaveUserKeywordRequest;
use App\Resources\UserKeywords\UserKeywordResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SaveOrUpdateUserKeywordController extends Controller
{
    public function __construct(
        private SaveOrUpdateUserKeywordAction $action
    ) {}

    public function saveOrUpdate(SaveUserKeywordRequest $request, ?int $id = null): JsonResponse
    {
        $dto      = $request->toDTO(Auth::id(), $id);
        $keywords = $this->action->saveOrUpdate($dto);

        $status = $id ? 200 : 201;
        $msg    = $id ? 'Palavra-chave atualizada com sucesso.' : 'Palavras-chave cadastradas com sucesso.';

        return response()->json([
            'message' => $msg,
            'data'    => $id
                ? new UserKeywordResource($keywords)
                : UserKeywordResource::collection($keywords),
        ], $status);
    }
}
