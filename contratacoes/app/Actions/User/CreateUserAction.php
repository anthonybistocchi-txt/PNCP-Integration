<?php

namespace App\Actions\User;

use App\DTOs\Auth\AuthDTO;
use App\Models\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateUserAction
{
    public function create(AuthDTO $dto): User
    {
        return User::create([
            'name'         => $dto->name,
            'email'        => $dto->email,
            'company_name' => $dto->companyName,
            'cpf'          => $dto->cpf,
            'cnpj'         => $dto->cnpj,
            'password'     => Hash::make($dto->password),
            'api_key'      => Str::uuid()->toString(),
        ]);
    }
}
