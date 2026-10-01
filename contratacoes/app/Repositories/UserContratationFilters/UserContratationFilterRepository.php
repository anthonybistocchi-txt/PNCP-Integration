<?php

namespace App\Repositories\UserContratationFilters;

use App\DTOs\UserContratationFilters\SaveUserContratationFilterDTO;
use App\Models\UserContratationFilters\UserContratationFilter;
use Illuminate\Database\Eloquent\Collection;

class UserContratationFilterRepository
{
    public function listByUserId(int $userId): Collection
    {
        return UserContratationFilter::where('user_id', $userId)
            ->orderBy('filter_type')
            ->orderBy('id')
            ->get();
    }

    /**
     * Grava todos os valores de uma dimensão de filtro em uma única query.
     */
    public function upsertMany(SaveUserContratationFilterDTO $filterToSave): Collection
    {
        $now = now();

        $filterRows = array_map(static fn(string $filterValue): array => [
            'user_id'                                                 => $filterToSave->userId,
            'filter_type'                                             => $filterToSave->filterType,
            'value'                                                   => $filterValue,
            'created_at'                                              => $now,
            'updated_at'                                              => $now,
        ], $filterToSave->values);

        UserContratationFilter::upsert(
            $filterRows,
            ['user_id', 'filter_type', 'value'],
            ['updated_at']
        );

        return UserContratationFilter::where('user_id', $filterToSave->userId)
            ->where('filter_type', $filterToSave->filterType)
            ->whereIn('value', $filterToSave->values)
            ->orderBy('id')
            ->get();
    }

    public function delete(int $id, int $userId): bool
    {
        return UserContratationFilter::where('id', $id)
            ->where('user_id', $userId)
            ->delete() > 0;
    }
}
