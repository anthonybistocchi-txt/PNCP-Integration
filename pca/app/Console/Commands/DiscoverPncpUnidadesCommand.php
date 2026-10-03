<?php

namespace App\Console\Commands;

use App\Jobs\Pncp\DiscoverPncpUnidadesJob;
use App\Repositories\Orgaos\OrgaoRepository;
use App\Repositories\Pncp\PncpOrgaoUnidadeRepository;
use Illuminate\Console\Command;

/**
 * Enriquecimento opcional: inventário de unidades em unidades_orgao.
 *
 * Não faz parte do pipeline de PCA (`import-orgaos` → `sync-pca`).
 * O SyncPcaDataJob já recebe as unidades via getPcaUnidades. Use este
 * comando sob demanda (ou com --force) quando precisar cruzar PCA ↔
 * contratação ↔ unidade fora do que já está em planos_pca.
 *
 * Só despacha para órgãos que JÁ TÊM plano no ano. Antes ele despachava um
 * job por órgão sem linha em unidades_orgao: ~5.400 órgãos sem PCA nunca
 * ganham essa linha (o PNCP responde 404), então voltavam todo dia, gastavam
 * o mesmo limitador das páginas e geravam milhares de failed_jobs.
 */
class DiscoverPncpUnidadesCommand extends Command
{
    protected $signature = 'pncp:discover-unidades
                            {--cnpj= : Processar apenas este CNPJ}
                            {--ano= : Ano para buscar unidades (padrão: ano atual)}
                            {--force : Reprocessa mesmo os que já têm unidades registradas}';

    protected $description = 'descobre unidades administrativas dos órgãos com base na tabela de contratações, de cada contratacao de cada orgao, e persiste em unidades_orgao';

    public function handle(
        OrgaoRepository $orgaoRepository,
        PncpOrgaoUnidadeRepository $orgaoUnidadeRepository
    ): int {
        $ano = $this->option('ano') ? (int) $this->option('ano') : now()->year;
        $cnpjEspecifico = $this->option('cnpj');

        $orgaosAtivos = $orgaoRepository->listWithPlans($ano, $cnpjEspecifico);

        if ($orgaosAtivos->isEmpty())
        {
            return self::SUCCESS;
        }

        foreach ($orgaosAtivos as $orgao)
        {
            if (! $this->option('force') && $orgaoUnidadeRepository->existsForOrgaoAno($orgao->cnpj, $ano))
            {
                continue; // se não for forçado, pula se já possui unidades registradas para este ano
            }

            DiscoverPncpUnidadesJob::dispatch($orgao->cnpj, $ano); // despacha job para descobrir unidades para este órgão e ano
        }

        return self::SUCCESS;
    }
}
