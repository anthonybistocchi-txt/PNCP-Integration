<?php

namespace App\Http\Controllers\PublicContratations;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicContratations\GetPublicContratationsRequest;
use App\Resources\GetPublicContratationsResource;
use App\Services\PublicContratations\PublicContratationsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GetPublicContratationsController extends Controller
{
    public function __construct(
        private PublicContratationsService $service
    ) {}

    public function get(GetPublicContratationsRequest $request)
    {
        $startedAt = now();

        $dto    = $request->toDTO();
        $result = $this->service->get($dto);

        $contratations = GetPublicContratationsResource::collection($result['data']);

        Log::info(json_encode([
            'event'       => 'API_READ_SUCCESS',
            'user_id'     => Auth::user()->id,
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
