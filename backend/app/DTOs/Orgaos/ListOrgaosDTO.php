<?php

namespace App\DTOs\Orgaos;

final class ListOrgaosDTO
{
    public function __construct(
        public readonly ?string $cursor = null,
        public readonly int $limit = 15,
    ) {}
}
