<?php

namespace App\Actions\UserKeywords;

use App\Services\UserKeywords\UserKeywordService;

class DeleteUserKeywordAction
{
    public function __construct(
        private UserKeywordService $service
    ) {}

    public function delete(int $id, int $userId): void
    {
        $this->service->delete($id, $userId);
    }
}
