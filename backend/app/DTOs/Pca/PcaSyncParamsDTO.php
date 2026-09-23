<?php

namespace App\DTOs\Pca;

final class PcaSyncParamsDTO
{
    public function __construct(
        public readonly ?int $ano = null,
        public readonly ?string $cnpj = null,
        public readonly bool $force = false,
    ) {}
}
