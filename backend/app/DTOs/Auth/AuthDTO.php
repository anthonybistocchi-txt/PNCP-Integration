<?php

namespace App\DTOs\Auth;

use App\Http\Requests\Auth\AuthRequest;

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
