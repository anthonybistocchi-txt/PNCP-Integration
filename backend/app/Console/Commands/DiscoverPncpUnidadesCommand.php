<?php

namespace App\Console\Commands;

use App\Integrations\PNCP\PncpClient;
use App\Jobs\Pncp\DiscoverPncpUnidadesJob;
use App\Models\Pncp\PncpOrgao;
use App\Models\Pncp\PncpOrgaoUnidade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DiscoverPncpUnidadesCommand extends Command
{
    protected $signature = 'pncp:discover-unidades 
                            {--cnpj= : Processar apenas este CNPJ}
                            {--ano= : Ano para buscar unidades (padrão: ano atual)}
                            {--force : Reprocessa mesmo os que já têm unidades registradas}';
                            
    protected $description = 'Descobre e salva unidades administrativas dos órgãos monitorados';

    public function handle(PncpClient $client): int
    {
        $ano = $this->option('ano') ? (int) $this->option('ano') : now()->year;
        $cnpjEspecifico = $this->option('cnpj');

        $query = PncpOrgao::where('ativo', true);
        if ($cnpjEspecifico) {
            $query->where('cnpj', $cnpjEspecifico);
        }

        $orgaos = $query->get();

        if ($orgaos->isEmpty()) {
            $this->warn('Nenhum órgão ativo encontrado para processar.');
            return self::SUCCESS;
        }

        $this->info("Iniciando descoberta de unidades para {$orgaos->count()} órgão(s). Ano: {$ano}");

        foreach ($orgaos as $orgao) {
            if (!$this->option('force') && PncpOrgaoUnidade::where('cnpj_orgao', $orgao->cnpj)->exists()) {
                $this->line("Pulando CNPJ {$orgao->cnpj} (já possui unidades e --force não foi usado).");
                continue;
            }

            $this->line("Despachando Job para CNPJ {$orgao->cnpj} no ano {$ano}.");
            DiscoverPncpUnidadesJob::dispatch($orgao->cnpj, $ano);
        }

        $this->info('Processo concluído.');
        return self::SUCCESS;
    }
}
