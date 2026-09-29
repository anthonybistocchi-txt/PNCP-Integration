<?php

namespace App\Actions\Contratacoes;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\Services\Contratacoes\ContratacoesService;

class ListContratacoesAction
{
    public function __construct(
        private ContratacoesService $service
    ) {}

    public function list(ListContratacoesDTO $dto): PaginatedResultDTO
    {
        return $this->service->list($dto);
    }
}
