<?php

namespace App\DTOs\Orgaos;

final class GetOrgaoDTO
{
    public function __construct(
        public readonly string $cnpj,
    ) {}
}
