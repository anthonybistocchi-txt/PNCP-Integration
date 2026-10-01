<?php

namespace App\Http\Controllers\Pca;

use App\Actions\Pca\ListPcaItemsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pca\ListPcaItemsRequest;
use App\Resources\Pca\PcaItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ListPcaItemsController extends Controller
{
    public function __construct(
        private ListPcaItemsAction $action
    ) {}

    public function list(ListPcaItemsRequest $request): JsonResponse
    {
        $startedAt = now();

        $dto    = $request->toDTO();
        $result = $this->action->list($dto);

        $items = PcaItemResource::collection($result->data);

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
            'data'        => $items,
            'page'        => $result->page,
            'count_page'  => $result->count_page,
            'count'       => $result->count,
            'next_cursor' => $result->next_cursor,
            'has_more'    => $result->has_more,
        ], 200);
    }
}
