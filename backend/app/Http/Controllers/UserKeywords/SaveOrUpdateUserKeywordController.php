<?php

namespace App\Http\Controllers\UserKeywords;

use App\Actions\UserKeywords\SaveOrUpdateUserKeywordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserKeywords\SaveUserKeywordRequest;
use App\Resources\UserKeywordResource;
use Illuminate\Support\Facades\Auth;

class SaveOrUpdateUserKeywordController extends Controller
{
    public function __construct(
        private SaveOrUpdateUserKeywordAction $action
    ) {}

    public function saveOrUpdate(SaveUserKeywordRequest $request, ?int $id = null)
    {
        $dto     = $request->toDTO(Auth::id(), $id);
        $keyword = $this->action->execute($dto);

        $status  = $id ? 200 : 201;
        $msg     = $id ? 'Palavra-chave atualizada com sucesso.' : 'Palavra-chave cadastrada com sucesso.';

        return response()->json([
            'message' => $msg,
            'data'    => new UserKeywordResource($keyword),
        ], $status);
    }
}
