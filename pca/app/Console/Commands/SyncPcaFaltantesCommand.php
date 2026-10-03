<?php

namespace App\Console\Commands;

use App\Jobs\Pca\SyncPcaItemsJob;
use App\Repositories\Pca\PcaPlanRepository;
use App\Repositories\Pca\PncpPcaCicloRepository;
use App\Support\Pca\PcaItemsFreshness;
use Illuminate\Console\Command;

/**
 * Reprocessa os planos cujos itens não estão em dia (equivalente ao
 * pncp:sync-contratacoes-faltantes, mas guiado pela marca d'água dos itens).
 *
 * Diferente do pncp:sync-pca, NÃO relista órgãos: não faz nenhuma chamada a
 * `consolidado/unidades` (eram ~7 mil requisições por rodada). Despacha
 * direto o sub-mestre (SyncPcaItemsJob) dos planos pendentes, que remede a
 * quantidade e redespacha as páginas. Os upserts são idempotentes.
 *
 * Plano pendente = nunca baixado por completo, atualizado no PNCP depois do
 * último download completo, ou com a marca mais velha que
 * `pncp.pca.refresh_after_days` (ver PcaItemsFreshness). Planos com um ciclo
 * ainda vivo (started dentro do prazo) são pulados para não duplicar páginas
 * que continuam na fila.
 *
 * A decisão usa a marca d'água, não o status dos ciclos: ciclos_pca guarda
 * histórico (um plano com ciclo `abandoned` antigo e um `finished` completo
 * depois NÃO é pendente). O resumo de ciclos em gap é só relatório.
 */
class SyncPcaFaltantesCommand extends Command
{
    protected $signature = 'pncp:sync-pca-faltantes
                            {--ano= : Ano do PCA (padrão: ano atual)}
                            {--cnpj= : Restringe a um órgão}
                            {--limit= : Máximo de planos despachados (padrão: pncp.pca.faltantes_max_per_run; 0 = sem teto)}
                            {--dry-run : Só lista pendentes e o resumo de ciclos em gap, sem despachar}';

    protected $description = 'Reprocessa planos de PCA cujos itens não estão em dia (ciclos abandonados, incompletos ou expirados), sem relistar órgãos.';

    public function handle(PcaPlanRepository $planoRepository, PncpPcaCicloRepository $ciclos): int
    {
        $ano    = $this->option('ano') ? (int) $this->option('ano') : now()->year;
        $cnpj   = $this->option('cnpj');
        $dryRun = (bool) $this->option('dry-run');
        $limit  = $this->option('limit') !== null
            ? max(0, (int) $this->option('limit'))
            : (int) config('pncp.pca.faltantes_max_per_run', 2000);

        $retryHours  = (int) config('pncp.retry_until_hours.pca', 72);
        $refreshDays = (int) config('pncp.pca.refresh_after_days', 30);

        // Planos com páginas possivelmente ainda na fila.
        $vivos = $ciclos->chavesEmAndamento($ano, $cnpj, $retryHours);

        // Nunca sincronizados primeiro, depois os mais antigos: com teto por
        // execução, o passivo mais velho não fica para trás indefinidamente.
        $planos = $planoRepository->cursorByAno($ano, $cnpj);

        $total       = 0;
        $emDia       = 0;
        $emFila      = 0;
        $pendentes   = 0;
        $despachados = 0;
        $acimaDoTeto = 0;
        $amostra     = [];

        foreach ($planos as $plano)
        {
            $total++;

            if (! PcaItemsFreshness::isPending($plano->itens_sincronizados_em, $plano->data_atualizacao, $refreshDays))
            {
                $emDia++;

                continue;
            }

            if (isset($vivos["{$plano->cnpj_orgao}:{$plano->sequencial_pca}"]))
            {
                $emFila++;

                continue;
            }

            $pendentes++;

            if (count($amostra) < 10)
            {
                $amostra[] = [
                    $plano->cnpj_orgao,
                    $plano->sequencial_pca,
                    $plano->itens_sincronizados_em?->format('Y-m-d H:i') ?? 'nunca',
                    $plano->data_atualizacao?->format('Y-m-d H:i') ?? '-',
                ];
            }

            if ($dryRun)
            {
                continue;
            }

            if ($limit > 0 && $despachados >= $limit)
            {
                $acimaDoTeto++;

                continue;
            }

            SyncPcaItemsJob::dispatch($plano->cnpj_orgao, (int) $plano->ano_pca, (int) $plano->sequencial_pca);
            $despachados++;
        }

        $this->info("Ano {$ano}: {$total} plano(s); {$emDia} em dia; {$emFila} com ciclo em andamento; {$pendentes} pendente(s).");

        if ($amostra !== [])
        {
            $this->line($dryRun ? 'Amostra dos pendentes (--dry-run):' : 'Amostra dos pendentes:');
            $this->table(['cnpj_orgao', 'sequencial', 'itens sincronizados em', 'data_atualizacao'], $amostra);
        }

        $this->reportGaps($ciclos, $ano, $cnpj, $retryHours);

        if ($dryRun)
        {
            $this->warn('--dry-run: nada foi despachado.');

            return self::SUCCESS;
        }

        $this->info("Despachados {$despachados} sub-mestre(s) de plano.");

        if ($acimaDoTeto > 0)
        {
            $this->warn("{$acimaDoTeto} plano(s) pendente(s) ficaram para a próxima execução (teto: {$limit}).");
        }

        return self::SUCCESS;
    }

    /**
     * Resumo histórico de ciclos de plano em gap (as mesmas definições de
     * abandoned / finished abaixo do anunciado / started vencido). É um
     * relatório: pode incluir ciclos antigos de planos que já foram
     * recuperados por um ciclo posterior.
     */
    private function reportGaps(PncpPcaCicloRepository $ciclos, int $ano, ?string $cnpj, int $retryHours): void
    {
        $gaps = $ciclos->resumoGaps($ano, $cnpj, $retryHours);

        if ($gaps->isEmpty())
        {
            $this->line('Nenhum ciclo de plano em gap.');

            return;
        }

        $this->line('Ciclos de plano em gap (histórico):');
        $this->table(
            ['status', 'ciclos', 'anunciado', 'processados'],
            $gaps->map(fn ($g) => [$g->status, $g->ciclos, $g->anunciado, $g->processados])->all()
        );
    }
}
