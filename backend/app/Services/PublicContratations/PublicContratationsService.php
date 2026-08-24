<?php

namespace App\Services\PublicContratations;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use App\Repositories\PublicContratations\PublicContratationRepository;

class PublicContratationsService
{
    public function __construct(
        private PublicContratationRepository $repository
    ) {}

    /**
     * Ponto de entrada do serviço: busca no banco local e engatilha o job de sincronização.
     */
    public function list(ListPublicContratationsDTO $dto): PaginatedResultDTO
    {
        return $this->repository->list($dto);
    }

    /**
     * Busca contratações relevantes por palavras-chave.
     */
    public function getRelevantByKeywords(array $keywords, ListRelevantContratationsDTO $dto): PaginatedResultDTO
    {
        if (empty($keywords)) {
            return new PaginatedResultDTO(
                data:        collect([]),
                page:        1,
                count_page:  0,
                count:       0,
                next_cursor: null,
                has_more:    false,
            );
        }
        return $this->repository->getRelevantByKeywords($keywords, $dto);
    }
}

