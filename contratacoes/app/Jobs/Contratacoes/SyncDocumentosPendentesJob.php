<?php

namespace App\Jobs\Contratacoes;

use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Repositories\Contratacoes\ContratacaoRepository;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Job Mestre da varredura de documentos pendentes: busca licitações de uma
 * modalidade com `documentos_sincronizados_em IS NULL`, sem nenhum filtro de data.
 *
 * É a rede de segurança do SyncDocumentosJob (a Fase 2, disparada ao fim de
 * cada ciclo de contratacoes). A Fase 2 cobre apenas os registros que o próprio
 * ciclo tocou; se o `/arquivos` do PNCP estiver fora do ar naquele momento
 * (como em 05–06/09, ~8h contínuas sem uma sondagem bem-sucedida), esses
 * registros ficariam com `documentos: null` para sempre, porque nenhum ciclo
 * futuro volta a olhar para eles.
 *
 * Roda de forma contínua (ver routes/console.php), não mais em duas rajadas
 * diárias. Cada janela leva no máximo `pncp.pending_documents_max_per_run`
 * registros por modalidade, pelos mais recentes primeiro — dimensionado para
 * caber no orçamento do limitador global dentro de uma janela, em vez de
 * tentar dar conta do passivo do dia numa execução só. Ver a conta em
 * `config/pncp.php`.
 */
class SyncDocumentosPendentesJob implements ShouldQueue, ShouldBeUnique
{
    // Dá 5 minutos de tolerância para descobrir os registros e enfileirar os workers.
    public $timeout = 300;

    // Precisa ser MENOR que o intervalo da drenagem (20 min), senão a própria
    // trava de unicidade engole as janelas seguintes. Era 6h com uniqueId por
    // dia: nesse formato, de 18 disparos num bloco de 6h, 17 eram descartados
    // em silêncio — o mesmo defeito que já tinha apagado o par 09h/15h. Só
    // precisa cobrir a vida real deste job, que é descobrir e despachar
    // (limitada pelo $timeout de 300s).
    public int $uniqueFor = 900; // 15 minutos

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $modalidade
    ) {}

    /**
     * Unique só por modalidade: sem a data, porque com drenagem contínua a
     * janela relevante é o `uniqueFor` acima, não o dia. Quem impede dois
     * ciclos de documentos concorrentes na mesma modalidade é a trava do
     * PncpSyncCycleTracker, que é semântica em vez de temporal.
     */
    public function uniqueId(): string
    {
        return 'docs_pending:' . $this->modalidade;
    }

    /**
     * Limite por tempo em vez de por tentativas.
     *
     * Uma janela, e não as 2h dos lotes: este job só consulta o banco e
     * despacha. Se não conseguir fazer isso dentro da janela, a janela
     * seguinte já traz a fotografia atualizada do que está nulo — insistir
     * seria despachar um recorte velho.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('pncp.pending_documents_interval_minutes', 20));
    }

    /**
     * Execute the job.
     */
    public function handle(PncpSyncCycleTracker $cycleTracker, ContratacaoRepository $contratacoes): void
    {
        $startedAt = now();

        // Sem filtro de data: pega qualquer pendência, de qualquer data — mas
        // com teto, pelos mais recentes primeiro (o passivo antigo tende a ser
        // de licitações já encerradas).
        $records = $contratacoes->listPendingDocuments(
            $this->modalidade,
            (int) config('pncp.pending_documents_max_per_run', 200)
        );

        if (empty($records))
        {
            // Log::info('PNCP_DOCUMENTOS_EMPTY', [
            //     'source'           => 'pendentes',
            //     'modalidade'       => $this->modalidade,
            //     'status'           => 'empty',
            //     'total_records'    => 0,
            //     'duration_seconds' => $startedAt->diffInSeconds(now()),
            // ]);

            return;
        }

        $lotes        = array_chunk($records, (int) config('pncp.documents_lote_size', 10));
        $totalLotes   = count($lotes);
        $totalRecords = count($records);
        $syncId       = (string) Str::uuid();

        // Trava por modalidade: se a Fase 2 (disparada automaticamente ao fim
        // da contratacoes) ainda estiver processando esta modalidade, desiste do
        // despacho em vez de redespachar os mesmos registros que ela já está
        // buscando. Ver PncpSyncCycleTracker::tryStartModalidade — foi essa
        // falta de coordenação que fez a varredura das 09h duplicar trabalho
        // sobre a Fase 2 ainda em andamento em 11/09.
        if (! $cycleTracker->tryStartModalidade($this->modalidade, $syncId))
        {
            Log::warning('PNCP_DOCUMENTOS_SKIPPED_COLLISION', [
                'source'         => 'pendentes',
                'modalidade'     => $this->modalidade,
                'total_records'  => $totalRecords,
                'active_sync_id' => $cycleTracker->activeSyncId($this->modalidade),
                'reason'         => 'Já existe um ciclo de documentos ativo para esta modalidade (Fase 2 ou outra varredura de pendentes). Registros continuam com documentos_sincronizados_em nulo; serão retomados na próxima execução agendada.',
            ]);

            return;
        }

        $cycleTracker->start($syncId, $totalLotes, $totalRecords);

        Log::info('PNCP_DOCUMENTOS_STARTED', [
            'sync_id'       => $syncId,
            'source'        => 'pendentes',
            'modalidade'    => $this->modalidade,
            'total_records' => $totalRecords,
            'total_lotes'   => $totalLotes,
            'started_at'    => $startedAt->toIso8601String(),
        ]);

        foreach ($lotes as $lote)
        {
            ProcessDocumentoPageJob::dispatch(
                $lote,
                $syncId,
                $this->modalidade,
                $startedAt->toIso8601String()
            );
        }
    }

    /**
     * Chamado pelo Laravel quando o job é descartado permanentemente (ex.:
     * retryUntil expirou antes de conseguir descobrir/despachar os lotes).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PNCP_DOCUMENTS_PENDING_PHASE_ABANDONED', [
            'modalidade' => $this->modalidade,
            'exception'  => get_class($exception),
            'message'    => $exception->getMessage(),
            'reason'     => 'Job Mestre da varredura de pendentes esgotou o retryUntil antes de conseguir descobrir/despachar os lotes. Os registros continuam com documentos_sincronizados_em nulo e serão retentados na próxima varredura agendada.',
        ]);
    }
}
