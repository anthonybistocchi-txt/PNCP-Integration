<?php

namespace App\Actions\Orgaos;

use App\DTOs\Orgaos\ListOrgaosDTO;
use App\DTOs\Paginate\PaginatedResultDTO;
use App\Services\Orgaos\OrgaoService;

class ListOrgaosAction
{
    public function __construct(
        private OrgaoService $service
    ) {}

    public function list(ListOrgaosDTO $dto): PaginatedResultDTO
    {
        return $this->service->list($dto);
    }
}
