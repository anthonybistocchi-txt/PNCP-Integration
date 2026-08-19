<?php

namespace App\Http\Controllers\PublicContratations;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicContratations\GetPublicContratationsRequest;
use App\Resources\GetPublicContratationsResource;
use App\Services\PublicContratations\PublicContratationsService;

class GetPublicContratationsController extends Controller
{
    public function __construct(
        private PublicContratationsService $service
    ) {}

    public function get(GetPublicContratationsRequest $request)
    {
        $result = $this->service->get($request->toDTO());

        $contratations = GetPublicContratationsResource::collection($result['data']);

        return response()->json([
            'data'        => $contratations,
            'count_page'  => $result['data']->count(),
            'count'       => $result['count'],
            'total_pages' => $result['total_pages'],
        ]);
    }
}
