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
                            {--cnpj= : CNPJ específico; sem valor, usa todos os ativos}
                            {--force-empty : Também consulta órgãos já verificados como sem PCA}';

    protected $description = 'Dispara os jobs para pegar cada PCA (Plano de Contratação Anual) de cada orgao salvo na tabela de contratações, e persistir em planos_pca com base no ano e cnpj do orgao. Órgãos verificados como sem PCA são pulados até pncp.pca.recheck_empty_days; planos com itens em dia (marca d\'água) não têm os itens baixados de novo.';

    public function handle(OrgaoRepository $orgaoRepository): int
    {
        $ano = $this->option('ano') ? (int) $this->option('ano') : now()->year;
        $cnpjEspecifico = $this->option('cnpj');

        // Pedir um CNPJ específico é intenção explícita: não aplica a memória
        // de "sem PCA", senão o comando devolveria 0 órgãos sem explicação.
        $incluirSemPca = (bool) $this->option('force-empty') || filled($cnpjEspecifico);

        $orgaosAtivos = $orgaoRepository->listForPcaSync($ano, $cnpjEspecifico, $incluirSemPca);

        if ($orgaosAtivos->isEmpty())
        {
            $this->info('Nenhum órgão a consultar.');

            return self::SUCCESS;
        }

        $this->info("Iniciando despacho de jobs de PCA para {$orgaosAtivos->count()} órgão(s). Ano: {$ano}");

        foreach ($orgaosAtivos as $orgao)
        {
            $dto = new PcaSyncParamsDTO(
                ano:  $ano,
                cnpj: $orgao->cnpj,
            );

            SyncPcaDataJob::dispatchFor($dto);
        }

        $this->info('Jobs despachados com sucesso. O processamento ocorrerá em background.');

        return self::SUCCESS;
    }
}
