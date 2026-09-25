<?php

namespace App\Actions\PublicContratations;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\Services\PublicContratations\PublicContratationsService;

class ListPublicContratationsAction
{
    public function __construct(
        private PublicContratationsService $service
    ) {}

    public function list(ListPublicContratationsDTO $dto): PaginatedResultDTO
    {
        return $this->service->list($dto);
    }
}
