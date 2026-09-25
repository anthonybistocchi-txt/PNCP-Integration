<?php

namespace App\DTOs\Auth;

final class AuthDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $companyName,
        public readonly ?string $cpf,
        public readonly ?string $cnpj,
        public readonly string $password,
    ) {}
}
