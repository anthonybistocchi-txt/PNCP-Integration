<?php

namespace App\Jobs\PublicContratations;

use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Models\PublicContratations\PublicContratation;
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
 * modalidade com `documents_fetched_at IS NULL`, sem nenhum filtro de data.
 *
 * É a rede de segurança do SyncPncpDocumentsJob (a Fase 2, disparada ao fim de
 * cada ciclo de listagem). A Fase 2 cobre apenas os registros que o próprio
 * ciclo tocou; se o `/arquivos` do PNCP estiver fora do ar naquele momento
 * (como em 05–06/09, ~8h contínuas sem uma sondagem bem-sucedida), esses
 * registros ficariam com `documentos: null` para sempre, porque nenhum ciclo
 * futuro volta a olhar para eles.
 *
 * Roda uma vez por dia, em horário próprio, fora do pico do cron principal.
 * Cada execução leva no máximo `pncp.pending_documents_max_per_run` registros
 * por modalidade, pelos mais recentes primeiro: despachar o passivo inteiro
 * de uma vez só enfileira lotes que morrem de MaxAttemptsExceeded antes de
 * serem atendidos. Ver o comentário em `config/pncp.php` para a conta do
 * valor escolhido.
 */
class SyncPncpPendingDocumentsJob implements ShouldQueue, ShouldBeUnique
{
    // Dá 5 minutos de tolerância para descobrir os registros e enfileirar os workers.
    public $timeout = 300;

    // Cobre a janela do retryUntil, senão o lock de unicidade expira
    // enquanto o job ainda está aguardando.
    public int $uniqueFor = 21600; // 6 horas

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $modalidade
    ) {}

    /**
     * Unique por modalidade + dia. Evita que duas execuções agendadas no
     * mesmo dia (ex.: 09h e 15h) colidam se a primeira ainda não terminou.
     */
    public function uniqueId(): string
    {
        return 'docs_pending:' . $this->modalidade . ':' . now()->format('Ymd');
    }

    /**
     * Limite por tempo em vez de por tentativas.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours', 6));
    }

    /**
     * Execute the job.
     */
    public function handle(PncpSyncCycleTracker $cycleTracker): void
    {
        $startedAt = now();

        // Sem filtro de data: pega qualquer pendência, de qualquer data — mas
        // com teto, pelos mais recentes primeiro (o passivo antigo tende a ser
        // de licitações já encerradas).
        $records = PublicContratation::select([
                'numero_controle_pncp',
                'orgao_cnpj',
                'ano_compra',
                'sequencial_compra',
            ])
            ->whereNull('documents_fetched_at')
            ->where('codigo_modalidade_contratacao', $this->modalidade)
            ->whereNotNull('sequencial_compra')
            ->orderByDesc('data_publicacao_pncp')
            ->limit((int) config('pncp.pending_documents_max_per_run', 2000))
            ->get()
            ->toArray();

        if (empty($records)) {
            Log::info('PNCP_DOCUMENTOS_FINISHED', [
                'source'           => 'pendentes',
                'modalidade'       => $this->modalidade,
                'status'           => 'empty',
                'total_records'    => 0,
                'duration_seconds' => $startedAt->diffInSeconds(now()),
            ]);

            return;
        }

        $lotes        = array_chunk($records, 50);
        $totalLotes   = count($lotes);
        $totalRecords = count($records);
        $syncId       = (string) Str::uuid();

        $cycleTracker->start($syncId, $totalLotes, $totalRecords);

        Log::info('PNCP_DOCUMENTOS_STARTED', [
            'sync_id'       => $syncId,
            'source'        => 'pendentes',
            'modalidade'    => $this->modalidade,
            'total_records' => $totalRecords,
            'total_lotes'   => $totalLotes,
            'started_at'    => $startedAt->toIso8601String(),
        ]);

        foreach ($lotes as $lote) {
            ProcessPncpDocumentPageJob::dispatch(
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
            'reason'     => 'Job Mestre da varredura de pendentes esgotou o retryUntil antes de conseguir descobrir/despachar os lotes. Os registros continuam com documents_fetched_at nulo e serão retentados na próxima varredura agendada.',
        ]);
    }
}
