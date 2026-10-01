<?php

namespace App\Http\Controllers\Contratacoes;

use App\Actions\Contratacoes\ListContratacoesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contratacoes\ListContratacoesRequest;
use App\Resources\Contratacoes\ListContratacoesResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ListContratacoesController extends Controller
{
    public function __construct(
        private ListContratacoesAction $action
    ) {}

    public function list(ListContratacoesRequest $request): JsonResponse
    {
        $startedAt = now();

        $dto    = $request->toDTO();
        $result = $this->action->list($dto);

        $contratations = ListContratacoesResource::collection($result->data);

        Log::info(json_encode([
            'event'          => 'API_READ_SUCCESS',
            'user_id'        => Auth::user()->id,
            'filters'        => (array) $dto,
            'results'        => [
                'count_page' => $result->count_page,
                'has_more'   => $result->has_more,
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
        ], 200);
    }
}

