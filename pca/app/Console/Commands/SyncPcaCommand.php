<?php

namespace App\Console\Commands;

use App\DTOs\Pca\PcaSyncParamsDTO;
use App\Jobs\Pca\SyncPcaDataJob;
use App\Repositories\Orgaos\OrgaoRepository;
use Illuminate\Console\Command;

class SyncPcaCommand extends Command
{
    protected $signature = 'pncp:sync-pca
                            {--ano= : Ano do PCA (padrão: ano atual)}
                            {--cnpj= : CNPJ específico; sem valor, usa todos os ativos}';

    protected $description = 'Dispara os jobs para sincronizar o PCA dos órgãos ativos';

    public function handle(OrgaoRepository $repository): int
    {
        $ano = $this->option('ano') ? (int) $this->option('ano') : now()->year;
        $cnpjEspecifico = $this->option('cnpj');

        $orgaos = $repository->listActive($cnpjEspecifico);

        if ($orgaos->isEmpty()) {
            $this->warn('Nenhum órgão ativo encontrado para sincronizar o PCA.');

            return self::SUCCESS;
        }

        $this->info("Iniciando despacho de jobs de PCA para {$orgaos->count()} órgão(s). Ano: {$ano}");

        foreach ($orgaos as $orgao) {
            $dto = new PcaSyncParamsDTO(
                ano: $ano,
                cnpj: $orgao->cnpj,
            );

            SyncPcaDataJob::dispatch($dto);
        }

        $this->info('Jobs despachados com sucesso. O processamento ocorrerá em background.');

        return self::SUCCESS;
    }
}
