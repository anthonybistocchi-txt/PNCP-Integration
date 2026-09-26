<?php

namespace App\Console\Commands;

use App\Jobs\Pncp\DiscoverPncpUnidadesJob;
use App\Repositories\Orgaos\OrgaoRepository;
use App\Repositories\Pncp\PncpOrgaoUnidadeRepository;
use Illuminate\Console\Command;

/**
 * Enriquecimento opcional: inventário de unidades em pncp_orgao_unidades.
 *
 * Não faz parte do pipeline diário de PCA (`import-orgaos` → `sync-pca`).
 * O SyncPcaDataJob já recebe as unidades via getPcaUnidades. Use este
 * comando sob demanda (ou com --force) quando precisar cruzar PCA ↔
 * contratação ↔ unidade fora do que já está em pca_plans.
 */
class DiscoverPncpUnidadesCommand extends Command
{
    protected $signature = 'pncp:discover-unidades
                            {--cnpj= : Processar apenas este CNPJ}
                            {--ano= : Ano para buscar unidades (padrão: ano atual)}
                            {--force : Reprocessa mesmo os que já têm unidades registradas}';

    protected $description = 'Enriquecimento opcional: descobre unidades administrativas dos órgãos (não é pré-requisito do sync PCA)';

    public function handle(
        OrgaoRepository $orgaoRepository,
        PncpOrgaoUnidadeRepository $unidadeRepository
    ): int {
        $ano = $this->option('ano') ? (int) $this->option('ano') : now()->year;
        $cnpjEspecifico = $this->option('cnpj');

        $orgaos = $orgaoRepository->listActive($cnpjEspecifico);

        if ($orgaos->isEmpty()) {
            $this->warn('Nenhum órgão ativo encontrado para processar.');

            return self::SUCCESS;
        }

        $this->info("Iniciando descoberta de unidades para {$orgaos->count()} órgão(s). Ano: {$ano}");

        foreach ($orgaos as $orgao) {
            if (! $this->option('force') && $unidadeRepository->existsForOrgaoAno($orgao->cnpj, $ano)) {
                $this->line("Pulando CNPJ {$orgao->cnpj} (já possui unidades em {$ano} e --force não foi usado).");

                continue;
            }

            $this->line("Despachando Job para CNPJ {$orgao->cnpj} no ano {$ano}.");
            DiscoverPncpUnidadesJob::dispatch($orgao->cnpj, $ano);
        }

        $this->info('Processo concluído.');

        return self::SUCCESS;
    }
}
