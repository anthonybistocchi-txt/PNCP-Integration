<?php

namespace App\Http\Controllers\Pca;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pca\ListPcaItemsRequest;
use App\Http\Resources\Pca\PcaItemResource;
use App\Services\Pca\PcaItemsService;
use Illuminate\Http\JsonResponse;

class ListPcaItemsController extends Controller
{
    public function list(ListPcaItemsRequest $request, PcaItemsService $service): JsonResponse
    {
        $dto = $request->toDTO();
        $result = $service->list($dto);

        return response()->json([
            'data' => PcaItemResource::collection($result['data']),
            'next_cursor' => $result['next_cursor'],
        ]);
    }
}
