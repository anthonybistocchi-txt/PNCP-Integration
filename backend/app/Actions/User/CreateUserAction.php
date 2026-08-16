<?php

namespace App\Actions\User;

use App\DTOs\AuthDTO;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateUserAction
{
    public function execute(AuthDTO $dto): User
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
