<?php

namespace App\DTOs\PublicContratations;

final class ListRelevantContratationsDTO
{
    public function __construct(
        public readonly ?string $cursor = null,
        public readonly int $limit = 15,
    ) {}
}

