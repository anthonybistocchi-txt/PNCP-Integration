<?php

namespace App\Http\Controllers\PublicContratations;

use App\Actions\PublicContratations\GetRelevantContratationsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicContratations\GetRelevantContratationsRequest;
use App\Resources\GetPublicContratationsResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GetRelevantContratationsController extends Controller
{
    public function __construct(
        private GetRelevantContratationsAction $action
    ) {}

    public function get(GetRelevantContratationsRequest $request)
    {
        $startedAt = now();
        $user = Auth::user();

        $dto    = $request->toDTO();
        $result = $this->action->execute($user->id, $dto);

        $contratations = GetPublicContratationsResource::collection($result['data']);

        Log::info(json_encode([
            'event'       => 'API_RELEVANT_SEARCH_SUCCESS',
            'user_id'     => $user->id,
            'filters'     => (array) $dto,
            'results'     => [
                'count_page'  => $result['count_page'],
                'has_more'    => $result['has_more'],
            ],
            'duration_ms' => $startedAt->diffInMilliseconds(now()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return response()->json([
            'data'        => $contratations,
            'count_page'  => $result['count_page'],
            'next_cursor' => $result['next_cursor'],
            'has_more'    => $result['has_more'],
        ]);
    }
}
