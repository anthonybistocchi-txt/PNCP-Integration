<?php

namespace App\DTOs\Auth;

final class MeDTO
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
    ) {}
}
