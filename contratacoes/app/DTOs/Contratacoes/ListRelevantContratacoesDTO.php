<?php

namespace App\DTOs\Contratacoes;

final class ListRelevantContratacoesDTO
{
    public function __construct(
        public readonly ?string $cursor = null,
        public readonly int $limit = 15,
    ) {}
}

