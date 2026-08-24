<?php

namespace App\Actions\PublicContratations;

use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use App\Services\PublicContratations\PublicContratationsService;
use App\Services\UserKeywords\UserKeywordService;

class ListRelevantContratationsAction
{
    public function __construct(
        private PublicContratationsService $publicContratationsService,
        private UserKeywordService $keywordService
    ) {}

    public function listByUserId(int $userId, ListRelevantContratationsDTO $dto)
    {
        $userKeywords = $this->keywordService->listByUserId($userId);

        $keywords = $userKeywords->pluck('keyword')->toArray();

        return $this->publicContratationsService->getRelevantByKeywords($keywords, $dto);
    }
}


