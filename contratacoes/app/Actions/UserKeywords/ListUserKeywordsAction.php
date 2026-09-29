<?php

namespace App\Actions\UserKeywords;

use App\Services\UserKeywords\UserKeywordService;
use Illuminate\Database\Eloquent\Collection;

class ListUserKeywordsAction
{
    public function __construct(
        private UserKeywordService $service
    ) {}

    public function listByUserId(int $userId): Collection
    {
        return $this->service->listByUserId($userId);
    }
}
