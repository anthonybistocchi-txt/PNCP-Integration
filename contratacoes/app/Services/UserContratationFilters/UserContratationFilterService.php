<?php

namespace App\Services\UserContratationFilters;

use App\DTOs\UserContratationFilters\SaveUserContratationFilterDTO;
use App\Exceptions\Domain\BusinessRuleException;
use App\Exceptions\Domain\ResourceNotFoundException;
use App\Models\UserContratationFilters\UserContratationFilter;
use App\Repositories\UserContratationFilters\UserContratationFilterRepository;
use Illuminate\Database\Eloquent\Collection;

class UserContratationFilterService
{
    public function __construct(
        private UserContratationFilterRepository $repository
    ) {}

    public function listByUserId(int $userId): Collection
    {
        return $this->repository->listByUserId($userId);
    }

    /**
     * Agrupa os filtros do usuário por dimensão, pronto para ser usado
     * na montagem da busca personalizada de contratações.
     *
     * @return array<string, list<string>>
     */
    public function listGroupedByType(int $userId): array
    {
        $userFilters = $this->repository->listByUserId($userId);

        $filtersGroupedByType = [];

        foreach ($userFilters as $userFilter)
        {
            $filtersGroupedByType[$userFilter->filter_type][] = $userFilter->value;
        }

        return $filtersGroupedByType;
    }

    public function saveOrUpdate(SaveUserContratationFilterDTO $filterToSave): Collection
    {
        if (!in_array($filterToSave->filterType, UserContratationFilter::TYPES, true))
        {
            throw new BusinessRuleException(
                "Tipo de filtro inválido: {$filterToSave->filterType}."
            );
        }

        return $this->repository->upsertMany($filterToSave);
    }

    public function delete(int $id, int $userId): void
    {
        $deleted = $this->repository->delete($id, $userId);

        if (!$deleted)
        {
            throw new ResourceNotFoundException('Filtro não encontrado ou não pertence ao usuário.');
        }
    }
}
