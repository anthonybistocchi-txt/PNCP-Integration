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
        $data = $this->service->get($request->toDTO());

        return GetPublicContratationsResource::collection($data);
    }
}
