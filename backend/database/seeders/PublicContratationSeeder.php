<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\PublicContratations\PublicContratation;

class PublicContratationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cria 300 registros falsos para testes de busca de palavras chave
        PublicContratation::factory()->count(300)->create();
        
        // Cria alguns registros fixos específicos para garantir que buscas funcionem
        PublicContratation::factory()->create([
            'objeto_compra' => 'Aquisição urgente de MACBOOK PRO M3 para equipe de TI e desenvolvimento.',
            'valor_total_estimado' => 150000.00
        ]);
        
        PublicContratation::factory()->create([
            'objeto_compra' => 'Contratação de empresa para reforma do refeitório e pintura.',
            'valor_total_estimado' => 85000.50
        ]);
    }
}
