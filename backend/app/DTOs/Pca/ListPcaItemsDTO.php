<?php

namespace App\DTOs\Pca;

final class ListPcaItemsDTO
{
    public function __construct(
        public readonly ?int $ano = null,
        public readonly ?string $cnpj = null,
        public readonly ?int $categoriaItem = null,
        public readonly ?string $search = null,
        public readonly ?string $cursor = null,
        public readonly int $limit = 15,
    ) {}
}
