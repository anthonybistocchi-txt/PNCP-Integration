<?php

namespace App\DTOs\UserKeywords;

final class SaveUserKeywordDTO
{
    /**
     * @param list<string> $keywords
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $keywords,
        public readonly ?int $id = null
    ) {}
}
