<?php

namespace App\DTOs\UserContratationFilters;

final class SaveUserContratationFilterDTO
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $filterType,
        public readonly array $values,
    ) {}
}
