<?php

namespace App\Http\Controllers\PublicContratations;

use App\Actions\PublicContratations\ListRelevantContratationsAction;
use App\Enums\PublicContratations\KeywordMatchMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicContratations\ListRelevantContratationsRequest;
use App\Resources\PublicContratations\ListPublicContratationsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ListRelevantContratationsController extends Controller
{
    public function __construct(
        private ListRelevantContratationsAction $action
    ) {}

    public function listInclude(ListRelevantContratationsRequest $request): JsonResponse
    {
        return $this->respond($request, KeywordMatchMode::Include);
    }

    public function listExclude(ListRelevantContratationsRequest $request): JsonResponse
    {
        return $this->respond($request, KeywordMatchMode::Exclude);
    }

    public function listBoth(ListRelevantContratationsRequest $request): JsonResponse
    {
        return $this->respond($request, KeywordMatchMode::Both);
    }

    private function respond(
        ListRelevantContratationsRequest $request,
        KeywordMatchMode $mode
    ): JsonResponse {
        $startedAt = now();
        $user = Auth::user();

        $dto    = $request->toDTO();
        $result = $this->action->listByUserId($user->id, $dto, $mode);

        $contratations = ListPublicContratationsResource::collection($result->data);

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
