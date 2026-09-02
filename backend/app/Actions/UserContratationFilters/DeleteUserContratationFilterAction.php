<?php

namespace App\Actions\UserContratationFilters;

use App\Services\UserContratationFilters\UserContratationFilterService;

class DeleteUserContratationFilterAction
{
    public function __construct(
        private UserContratationFilterService $service
    ) {}

    public function delete(int $id, int $userId): void
    {
        $this->service->delete($id, $userId);
    }
}
