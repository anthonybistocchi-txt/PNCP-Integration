<?php

namespace App\Services\PublicContratations;

use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use App\Repositories\PublicContratationRepository;

class PublicContratationsService
{
    public function __construct(
        private PublicContratationRepository $repository
    ) {}

    /**
     * Ponto de entrada do serviço: busca no banco local e engatilha o job de sincronização.
     */
    public function list(ListPublicContratationsDTO $dto): array
    {
        return $this->repository->getByFilters($dto);
    }

    /**
     * Busca contratações relevantes por palavras-chave.
     */
    public function getRelevantByKeywords(array $keywords, ListRelevantContratationsDTO $dto): array
    {
        if (empty($keywords)) {
            return [
                'data'        => collect([]),
                'count_page'  => 0,
                'next_cursor' => null,
                'has_more'    => false,
            ];
        }
        return $this->repository->getRelevantByKeywords($keywords, $dto);
    }
}

