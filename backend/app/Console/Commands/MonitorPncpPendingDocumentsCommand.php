<?php

namespace App\Console\Commands;

use App\Models\PublicContratations\PublicContratation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fotografa o passivo atual de `documents_fetched_at IS NULL` por modalidade
 * e alerta (log + exit code != 0) quando alguma modalidade ultrapassa o
 * limiar configurado.
 *
 * Não corrige nada sozinho — é só o sinal de que o teto configurado em
 * `pncp.pending_documents_max_per_run` parou de dar conta do fluxo diário de
 * uma modalidade específica (decisão registrada: manter o Fase 1/Fase 2
 * desacoplado em vez de voltar ao fetch inline, e monitorar esse passivo em
 * vez de assumir que o teto de 2000 é suficiente para sempre).
 *
 * Pensado para rodar 1x/dia, depois da varredura das 21h (que historicamente
 * leva ~3h para esvaziar a fila — ver routes/console.php).
 */
class MonitorPncpPendingDocumentsCommand extends Command
{
    protected $signature = 'pncp:monitorar-documentos-pendentes
        {--limite= : Sobrescreve o limiar de alerta (padrão: config pncp.pending_documents_alert_threshold)}';

    protected $description = 'Reporta e alerta sobre o passivo de documentos pendentes (documents_fetched_at nulo) por modalidade.';

    private const NOMES_MODALIDADE = [
        1  => 'Leilão - Eletrônico',
        2  => 'Diálogo Competitivo',
        3  => 'Concurso',
        4  => 'Concorrência - Eletrônica',
        5  => 'Concorrência - Presencial',
        6  => 'Pregão - Eletrônico',
        7  => 'Pregão - Presencial',
        8  => 'Dispensa de Licitação',
        9  => 'Inexigibilidade',
        10 => 'Manifestação de Interesse',
    ];

    public function handle(): int
    {
        $limiar = $this->option('limite') !== null
            ? max(0, (int) $this->option('limite'))
            : (int) config('pncp.pending_documents_alert_threshold', 3000);

        // Mesmo WHERE usado por SyncPncpPendingDocumentsJob, para o número
        // bater com o que a varredura de fato enxerga como pendente.
        $porModalidade = PublicContratation::query()
            ->whereNull('documents_fetched_at')
            ->whereNotNull('sequencial_compra')
            ->selectRaw('codigo_modalidade_contratacao, count(*) as total')
            ->groupBy('codigo_modalidade_contratacao')
            ->orderByDesc('total')
            ->pluck('total', 'codigo_modalidade_contratacao');

        if ($porModalidade->isEmpty()) {
            $this->info('Nenhum documento pendente. Passivo zerado.');

            return self::SUCCESS;
        }

        $totalGeral = (int) $porModalidade->sum();
        $emAlerta   = [];
        $linhas     = [];

        foreach ($porModalidade as $modalidade => $total) {
            $modalidade = (int) $modalidade;
            $total      = (int) $total;
            $nome       = self::NOMES_MODALIDADE[$modalidade] ?? "Modalidade {$modalidade}";
            $emAlertaFlag = $total > $limiar;

            $linhas[] = [$modalidade, $nome, $total, $emAlertaFlag ? '⚠ ACIMA DO LIMIAR' : 'ok'];

            if ($emAlertaFlag) {
                $emAlerta[$modalidade] = $total;
            }
        }

        $this->info("Passivo de documentos pendentes (documents_fetched_at nulo). Limiar de alerta: {$limiar}.");
        $this->table(['Modalidade', 'Nome', 'Pendentes', 'Status'], $linhas);
        $this->info("Total geral pendente: {$totalGeral}.");

        if ($emAlerta === []) {
            Log::info('PNCP_PENDING_DOCS_BACKLOG_OK', [
                'total_geral'    => $totalGeral,
                'por_modalidade' => $porModalidade->toArray(),
                'limiar'         => $limiar,
            ]);

            return self::SUCCESS;
        }

        Log::warning('PNCP_PENDING_DOCS_BACKLOG_ALERTA', [
            'modalidades_em_alerta' => $emAlerta,
            'total_geral'           => $totalGeral,
            'limiar'                => $limiar,
            'mensagem'              => 'Passivo de documentos pendentes acima do limiar em pelo menos uma '
                . 'modalidade. Sinal de que pncp.pending_documents_max_per_run pode não estar dando conta do '
                . 'fluxo diário dessa(s) modalidade(s) — considerar subir o teto, aumentar a frequência da '
                . 'varredura, ou priorizar registros mais antigos (risco de starvation documentado em '
                . 'SyncPncpPendingDocumentsJob, ordenação por data_publicacao_pncp DESC).',
        ]);

        $detalhes = [];
        foreach ($emAlerta as $modalidade => $total) {
            $detalhes[] = "modalidade {$modalidade} ({$total})";
        }

        $this->error('Passivo acima do limiar em: ' . implode(', ', $detalhes));

        return self::FAILURE;
    }
}
