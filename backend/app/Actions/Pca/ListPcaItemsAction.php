<?php

namespace App\Actions\Pca;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\Pca\ListPcaItemsDTO;
use App\Services\Pca\PcaItemsService;

class ListPcaItemsAction
{
    public function __construct(
        private PcaItemsService $service
    ) {}

    public function list(ListPcaItemsDTO $dto): PaginatedResultDTO
    {
        return $this->service->list($dto);
    }
}
