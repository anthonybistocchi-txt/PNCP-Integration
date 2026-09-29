<?php

namespace App\Services\Contratacoes;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\DTOs\Contratacoes\ListRelevantContratacoesDTO;
use App\DTOs\Contratacoes\PersonalizedContratacaoFiltersDTO;
use App\Repositories\Contratacoes\ContratacaoRepository;

class ContratacoesService
{
    public function __construct(
        private ContratacaoRepository $repository
    ) {}

    /**
     * Ponto de entrada do serviço: busca no banco local e engatilha o job de sincronização.
     */
    public function list(ListContratacoesDTO $dto): PaginatedResultDTO
    {
        return $this->repository->list($dto);
    }

    /**
     * Busca contratações personalizadas: combina os filtros salvos do cliente
     * (uf, modalidade, esfera, etc) com palavras-chave de inclusão e exclusão.
     */
    public function getPersonalized(PersonalizedContratacaoFiltersDTO $filters, ListRelevantContratacoesDTO $dto): PaginatedResultDTO
    {
        return $this->repository->searchPersonalized($filters, $dto);
    }
}
