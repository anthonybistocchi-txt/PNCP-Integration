<?php

namespace App\Actions\UserContratationFilters;

use App\DTOs\UserContratationFilters\SaveUserContratationFilterDTO;
use App\Services\UserContratationFilters\UserContratationFilterService;
use Illuminate\Database\Eloquent\Collection;

class SaveOrUpdateUserContratationFilterAction
{
    public function __construct(
        private UserContratationFilterService $service
    ) {}

    public function saveOrUpdate(SaveUserContratationFilterDTO $filterToSave): Collection
    {
        return $this->service->saveOrUpdate($filterToSave);
    }
}
