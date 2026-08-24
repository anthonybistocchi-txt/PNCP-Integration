<?php

namespace App\DTOs\UserKeywords;

final class SaveUserKeywordDTO
{
    public function __construct(
        public readonly int $userId,
        public readonly string $keyword,
        public readonly ?int $id = null
    ) {}
}
