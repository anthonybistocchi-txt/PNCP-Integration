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

    protected $description = 'Dispara os jobs para pegar cada PCA (Plano de Contratação Anual) de cada orgao salvo na tabela de contratações, e persistir em planos_pca com base no ano e cnpj do orgao';

    public function handle(OrgaoRepository $orgaoRepository): int
    {
        $ano = $this->option('ano') ? (int) $this->option('ano') : now()->year;
        $cnpjEspecifico = $this->option('cnpj');

        $orgaosAtivos = $orgaoRepository->listActive($cnpjEspecifico);

        if ($orgaosAtivos->isEmpty())
        {
            return self::SUCCESS;
        }

        $this->info("Iniciando despacho de jobs de PCA para {$orgaosAtivos->count()} órgão(s). Ano: {$ano}");

        foreach ($orgaosAtivos as $orgao)
        {
            $dto = new PcaSyncParamsDTO(
                ano:  $ano,
                cnpj: $orgao->cnpj,
            );

            SyncPcaDataJob::dispatch($dto);
        }

        $this->info('Jobs despachados com sucesso. O processamento ocorrerá em background.');

        return self::SUCCESS;
    }
}
