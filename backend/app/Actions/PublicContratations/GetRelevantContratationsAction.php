<?php

namespace App\Actions\PublicContratations;

use App\DTOs\PublicContratations\GetRelevantContratationsDTO;
use App\Services\PublicContratations\PublicContratationsService;
use App\Services\UserKeywords\UserKeywordService;

class GetRelevantContratationsAction
{
    public function __construct(
        private PublicContratationsService $publicContratationsService,
        private UserKeywordService $keywordService
    ) {}

    public function execute(int $userId, GetRelevantContratationsDTO $dto): array
    {
        $userKeywords = $this->keywordService->getByUserId($userId);
        
        $keywords = $userKeywords->pluck('keyword')->toArray();

        return $this->publicContratationsService->getRelevantByKeywords($keywords, $dto);
    }
}

