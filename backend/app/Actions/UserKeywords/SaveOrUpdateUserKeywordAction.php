<?php

namespace App\Actions\UserKeywords;

use App\DTOs\UserKeywords\SaveUserKeywordDTO;
use App\Models\UserKeyword;
use App\Services\UserKeywords\UserKeywordService;

class SaveOrUpdateUserKeywordAction
{
    public function __construct(
        private UserKeywordService $service
    ) {}

    public function execute(SaveUserKeywordDTO $dto): UserKeyword
    {
        return $this->service->saveOrUpdate($dto);
    }
}
