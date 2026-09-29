<?php

namespace App\Actions\UserContratationFilters;

use App\Services\UserContratationFilters\UserContratationFilterService;
use Illuminate\Database\Eloquent\Collection;

class ListUserContratationFiltersAction
{
    public function __construct(
        private UserContratationFilterService $service
    ) {}

    public function listByUserId(int $userId): Collection
    {
        return $this->service->listByUserId($userId);
    }
}
