<?php

namespace App\DTOs\PublicContratations;

final class GetRelevantContratationsDTO
{
    public function __construct(
        public readonly ?string $cursor = null,
        public readonly int $limit = 15,
    ) {}
}
