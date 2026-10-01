<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $apiKey = env('ADMIN_API_KEY');

        if (!$apiKey)
        {
            $this->command->warn('A variável de ambiente ADMIN_API_KEY não está definida no arquivo .env.');
        }

        User::updateOrCreate(
            ['email' => 'administrador@gmail.com'], // Cria se não existir, ou atualiza se já existir
            [
                'name'     => 'Administrador',
                'password' => Hash::make('Onb00697@'), // Senha padrão
                'api_key'  => $apiKey,
            ]
        );
    }
}
