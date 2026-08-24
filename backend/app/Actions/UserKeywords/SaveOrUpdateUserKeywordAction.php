<?php

namespace App\Actions\UserKeywords;

use App\DTOs\UserKeywords\SaveUserKeywordDTO;
use App\Models\UserKeywords\UserKeyword;
use App\Services\UserKeywords\UserKeywordService;

class SaveOrUpdateUserKeywordAction
{
    public function __construct(
        private UserKeywordService $service
    ) {}

    public function saveOrUpdate(SaveUserKeywordDTO $dto): UserKeyword
    {
        return $this->service->saveOrUpdate($dto);
    }
}
