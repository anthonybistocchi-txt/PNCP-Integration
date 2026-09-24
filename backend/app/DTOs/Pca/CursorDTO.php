<?php

namespace App\DTOs\Pca;

readonly class CursorDTO
{
    public function __construct(
        public string $date,
        public int $id,
    ) {
    }
}
