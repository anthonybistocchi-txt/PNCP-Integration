<?php

namespace App\DTOs;

use App\Http\Requests\AuthRequest;

final class AuthDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $companyName,
        public ?string $cpf,
        public ?string $cnpj,
        public string $password,
    ) {}

    public static function fromRequest(AuthRequest $request): self
    {
        return new self(
            name: $request->name,
            email: $request->email,
            companyName: $request->companyName,
            cpf: $request->cpf,
            cnpj: $request->cnpj,
            password: $request->password,
        );
    }
}
