<?php

namespace App\Services\Pca;

use App\DTOs\Pca\ListPcaItemsDTO;
use App\Repositories\Pca\PcaItemRepository;

class PcaItemsService
{
    public function __construct(
        private PcaItemRepository $repository
    ) {}

    public function list(ListPcaItemsDTO $dto): array
    {
        return $this->repository->list($dto);
    }
}
