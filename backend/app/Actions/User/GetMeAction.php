<?php

namespace App\Actions\User;

use App\DTOs\Auth\MeDTO;
use App\Exceptions\Domain\ResourceNotFoundException;
use App\Models\Auth\User;
use Illuminate\Support\Facades\Hash;

class GetMeAction
{
    public function get(MeDTO $dto): User
    {
        $user = User::where('email', $dto->email)->first();

        if (!$user || !Hash::check($dto->password, $user->password) || !$user->isActive()) {
            throw new ResourceNotFoundException('Usuário não encontrado.');
        }

        return $user;
    }
}
