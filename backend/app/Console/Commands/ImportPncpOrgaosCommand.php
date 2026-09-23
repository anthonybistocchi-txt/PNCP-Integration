<?php

namespace App\Console\Commands;

use App\Models\Pncp\PncpOrgao;
use App\Models\PublicContratations\PublicContratation;
use Illuminate\Console\Command;

class ImportPncpOrgaosCommand extends Command
{
    protected $signature = 'pncp:import-orgaos {--dry-run : Exibe os CNPJs que seriam importados sem persistir}';
    protected $description = 'Importa CNPJs únicos das contratações públicas para a tabela pncp_orgaos';

    public function handle(): int
    {
        $this->info('Iniciando importação de CNPJs únicos de public_contratations...');

        $cnpjs = PublicContratation::select('orgao_cnpj')
            ->distinct()
            ->whereNotNull('orgao_cnpj')
            ->pluck('orgao_cnpj');

        $this->info("Encontrados {$cnpjs->count()} CNPJs únicos.");

        if ($this->option('dry-run')) {
            $this->info('Dry-run finalizado.');
            return self::SUCCESS;
        }

        $novos = 0;
        $existentes = 0;

        foreach ($cnpjs as $cnpj) {
            $orgao = PncpOrgao::where('cnpj', $cnpj)->first();

            if (! $orgao) {
                PncpOrgao::create([
                    'cnpj' => $cnpj,
                    'importado_de' => 'public_contratations',
                ]);
                $novos++;
            } else {
                $existentes++;
            }
        }

        $this->info("Importação concluída. Novos: {$novos}, Existentes: {$existentes}.");

        return self::SUCCESS;
    }
}
