<?php

namespace App\DTOs\PublicContratations;

readonly class CursorDTO
{
    public function __construct(
        public string $date,
        public string $numero_controle,
    ) {
    }
}
