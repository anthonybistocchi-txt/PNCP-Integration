<?php

namespace App\DTOs\Pca;

final class PcaSyncParamsDTO
{
    public function __construct(
        public readonly int $ano,
        public readonly string $cnpj,
        public readonly bool $force = false,
    ) {}
}
