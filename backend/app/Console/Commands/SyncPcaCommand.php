<?php

namespace App\Console\Commands;

use App\DTOs\Pca\PcaSyncParamsDTO;
use App\Jobs\Pca\SyncPcaDataJob;
use App\Models\Pncp\PncpOrgao;
use Illuminate\Console\Command;

class SyncPcaCommand extends Command
{
    protected $signature = 'pncp:sync-pca 
                            {--ano= : Ano do PCA (padrão: ano atual)}
                            {--cnpj= : CNPJ específico; sem valor, usa todos os ativos}
                            {--force : Ignora verificação de ciclo em andamento}';

    protected $description = 'Dispara os jobs para sincronizar o PCA dos órgãos ativos';

    public function handle(): int
    {
        $ano = $this->option('ano') ? (int) $this->option('ano') : now()->year;
        $cnpjEspecifico = $this->option('cnpj');

        $query = PncpOrgao::where('ativo', true);
        if ($cnpjEspecifico) {
            $query->where('cnpj', $cnpjEspecifico);
        }

        $orgaos = $query->get();

        if ($orgaos->isEmpty()) {
            $this->warn('Nenhum órgão ativo encontrado para sincronizar o PCA.');
            return self::SUCCESS;
        }

        $this->info("Iniciando despacho de jobs de PCA para {$orgaos->count()} órgão(s). Ano: {$ano}");

        foreach ($orgaos as $orgao) {
            $dto = new PcaSyncParamsDTO(
                ano: $ano,
                cnpj: $orgao->cnpj,
                force: (bool) $this->option('force')
            );

            SyncPcaDataJob::dispatch($dto);
        }

        $this->info('Jobs despachados com sucesso. O processamento ocorrerá em background.');
        return self::SUCCESS;
    }
}
