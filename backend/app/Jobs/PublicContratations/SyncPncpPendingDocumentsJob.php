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
 * Job Mestre da varredura de documentos pendentes: busca TODAS as licitações
 * de uma modalidade com `documents_fetched_at IS NULL`, sem nenhum filtro de
 * data.
 *
 * Existe porque o SyncPncpDocumentsJob (disparado automaticamente ao fim de
 * cada ciclo de listagem) só olha para o intervalo de datas daquele ciclo
 * (ontem→hoje, no cron diário). Uma licitação publicada no dia X só é
 * candidata a ter os documentos buscados nas execuções dos dias X e X+1 —
 * depois disso, nenhum cron futuro olha para essa data de novo. Se o
 * `/arquivos` do PNCP ficar fora do ar por mais que isso (como em 05–06/09,
 * ~8h contínuas sem uma sondagem bem-sucedida), esses registros ficariam
 * com `documentos: null` para sempre.
 *
 * Este job é disparado por um comando agendado separado (várias vezes ao
 * dia, em horários diferentes do cron principal), garantindo que qualquer
 * pendência eventualmente seja retentada, não importa há quanto tempo foi
 * publicada.
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

        Log::info('SyncPncpPendingDocumentsJob (MASTER) started.', [
            'modalidade' => $this->modalidade,
        ]);

        // Sem whereBetween: pega qualquer pendência, de qualquer data.
        $records = PublicContratation::select([
                'numero_controle_pncp',
                'orgao_cnpj',
                'ano_compra',
                'sequencial_compra',
            ])
            ->whereNull('documents_fetched_at')
            ->where('codigo_modalidade_contratacao', $this->modalidade)
            ->whereNotNull('sequencial_compra')
            ->get()
            ->toArray();

        if (empty($records)) {
            Log::info('SyncPncpPendingDocumentsJob (MASTER) finished. No records pending document sync.', [
                'modalidade' => $this->modalidade,
            ]);

            return;
        }

        $lotes      = array_chunk($records, 50);
        $totalLotes = count($lotes);
        $syncId     = (string) Str::uuid();

        $cycleTracker->start($syncId, $totalLotes);

        foreach ($lotes as $lote) {
            ProcessPncpDocumentPageJob::dispatch(
                $lote,
                $syncId,
                $this->modalidade,
                $startedAt->toIso8601String()
            );
        }

        Log::info('DOCUMENTS_PENDING_SYNC_DISPATCHED', [
            'sync_id'       => $syncId,
            'modalidade'    => $this->modalidade,
            'total_records' => count($records),
            'total_lotes'   => $totalLotes,
            'started_at'    => $startedAt->toIso8601String(),
        ]);
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
