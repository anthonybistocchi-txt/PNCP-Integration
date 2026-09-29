<?php

namespace App\Services\Orgaos;

use App\DTOs\Orgaos\ListOrgaosDTO;
use App\DTOs\Paginate\PaginatedResultDTO;
use App\Models\Orgaos\Orgao;
use App\Repositories\Orgaos\OrgaoRepository;

class OrgaoService
{
    public function __construct(
        private OrgaoRepository $repository
    ) {}

    public function list(ListOrgaosDTO $dto): PaginatedResultDTO
    {
        return $this->repository->listPaginated($dto);
    }

    public function findWithPcaData(string $cnpj): Orgao
    {
        return $this->repository->findWithPcaData($cnpj);
    }
}
