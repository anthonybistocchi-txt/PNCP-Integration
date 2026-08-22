<?php

namespace App\Services\PublicContratations;

use App\DTOs\PublicContratations\GetPublicContratationsDTO;
use App\DTOs\PublicContratations\GetRelevantContratationsDTO;
use App\Repositories\PublicContratationRepository;

class PublicContratationsService
{
    public function __construct(
        private PublicContratationRepository $repository
    ) {}

    /**
     * Ponto de entrada do serviço: busca no banco local e engatilha o job de sincronização.
     */
    public function get(GetPublicContratationsDTO $dto): array
    {
        return $this->repository->getByFilters($dto);
    }

    /**
     * Busca contratações relevantes por palavras-chave.
     */
    public function getRelevantByKeywords(array $keywords, GetRelevantContratationsDTO $dto): array
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
