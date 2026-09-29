<?php

namespace App\Http\Controllers\Contratacoes;

use App\Actions\Contratacoes\ListRelevantContratacoesAction;
use App\Enums\Contratacoes\KeywordMatchMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contratacoes\ListRelevantContratacoesRequest;
use App\Resources\Contratacoes\ListContratacoesResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ListRelevantContratacoesController extends Controller
{
    public function __construct(
        private ListRelevantContratacoesAction $action
    ) {}

    public function listInclude(ListRelevantContratacoesRequest $request): JsonResponse
    {
        return $this->respond($request, KeywordMatchMode::Include);
    }

    public function listBoth(ListRelevantContratacoesRequest $request): JsonResponse
    {
        return $this->respond($request, KeywordMatchMode::Both);
    }

    private function respond(
        ListRelevantContratacoesRequest $request,
        KeywordMatchMode $mode
    ): JsonResponse {
        $startedAt = now();
        $user = Auth::user();

        $dto    = $request->toDTO();
        $result = $this->action->listByUserId($user->id, $dto, $mode);

        $contratations = ListContratacoesResource::collection($result->data);

        Log::info(json_encode([
            'event'       => 'API_RELEVANT_SEARCH_SUCCESS',
            'user_id'     => $user->id,
            'mode'        => $mode->value,
            'filters'     => (array) $dto,
            'results'     => [
                'count'       => $result->count,
                'count_page'  => $result->count_page,
                'has_more'    => $result->has_more,
            ],
            'duration_ms' => $startedAt->diffInMilliseconds(now()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return response()->json([
            'data'        => $contratations,
            'page'        => $result->page,
            'count_page'  => $result->count_page,
            'count'       => $result->count,
            'next_cursor' => $result->next_cursor,
            'has_more'    => $result->has_more,
        ]);
    }
}
