<?php

namespace App\Http\Controllers\Orgaos;

use App\Actions\Orgaos\ListOrgaosAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orgaos\ListOrgaosRequest;
use App\Resources\Orgaos\OrgaoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ListOrgaosController extends Controller
{
    public function __construct(
        private ListOrgaosAction $action
    ) {}

    public function list(ListOrgaosRequest $request): JsonResponse
    {
        $startedAt = now();

        $result = $this->action->list($request->toDTO());

        $orgaos = OrgaoResource::collection($result->data);

        Log::info(json_encode([
            'event'          => 'API_READ_SUCCESS',
            'user_id'        => Auth::user()->id,
            'results'        => [
                'count_page' => $result->count_page,
                'has_more'   => $result->has_more,
            ],
            'duration_ms' => $startedAt->diffInMilliseconds(now()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return response()->json([
            'data'        => $orgaos,
            'page'        => $result->page,
            'count_page'  => $result->count_page,
            'count'       => $result->count,
            'next_cursor' => $result->next_cursor,
            'has_more'    => $result->has_more,
        ], 200);
    }
}
