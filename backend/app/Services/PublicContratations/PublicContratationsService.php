<?php

namespace App\Services\PublicContratations;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use App\DTOs\PublicContratations\PersonalizedContratationFiltersDTO;
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
     * Busca contratações personalizadas: combina os filtros salvos do cliente
     * (uf, modalidade, esfera, etc) com palavras-chave de inclusão e exclusão.
     */
    public function getPersonalized(PersonalizedContratationFiltersDTO $filters, ListRelevantContratationsDTO $dto): PaginatedResultDTO
    {
        return $this->repository->searchPersonalized($filters, $dto);
    }
}
