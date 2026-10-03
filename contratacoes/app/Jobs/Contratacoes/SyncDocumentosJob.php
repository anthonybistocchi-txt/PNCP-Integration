<?php

namespace App\Jobs\Contratacoes;

use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Repositories\Contratacoes\ContratacaoRepository;
use Carbon\Carbon;
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
 * Job Mestre da Fase 2: busca e distribui a sincronização de documentos
 * (editais, anexos) para as licitações que ainda não tiveram seus documentos
 * processados (documentos_sincronizados_em IS NULL).
 *
 * É disparado automaticamente pelo ProcessContratacaoPageJob quando o ciclo de
 * contratacoes de uma modalidade é concluído. Não requer cron adicional.
 */
class SyncDocumentosJob implements ShouldQueue, ShouldBeUnique
{
    // Dá 5 minutos de tolerância para descobrir os registros e enfileirar os workers.
    public $timeout = 300;

    // Cobre a janela do retryUntil, senão o lock de unicidade expira
    // enquanto o job ainda está aguardando. O uniqueId inclui o intervalo de
    // datas, então esta trava é naturalmente uma por modalidade por ciclo —
    // não corre o risco de engolir janelas como a da drenagem contínua.
    public int $uniqueFor = 7200; // 2 horas

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ListContratacoesDTO $dto,
        protected string $cycleStartedAt = ''
    ) {}

    /**
     * Unique por modalidade + intervalo de datas.
     * Evita que dois ciclos da mesma modalidade no mesmo dia gerem jobs duplicados.
     */
    public function uniqueId(): string
    {
        return 'docs:' . implode(':', [
            $this->dto->codigoModalidadeContratacao,
            $this->dto->dataInicial,
            $this->dto->dataFinal,
        ]);
    }

    /**
     * Limite por tempo em vez de por tentativas.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours.documentos', 2));
    }

    /**
     * Execute the job.
     */
    public function handle(PncpSyncCycleTracker $cycleTracker, ContratacaoRepository $contratacoes): void
    {
        $startedAt = now();

        // A contratacoes consulta /contratacoes/atualizacao, então o ciclo traz
        // licitações publicadas meses atrás que foram atualizadas ontem.
        // Recortar por data_publicacao_pncp deixava justamente essas fora da
        // Fase 2 para sempre: nenhum ciclo futuro olha para a data delas, e só
        // a varredura de pendentes as alcançava. O upsert da Fase 1 toca
        // updated_at, então "atualizado desde o início do ciclo" é o recorte
        // exato do que este ciclo trouxe — e continua limitado ao volume do
        // dia, sem despachar o passivo histórico inteiro.
        $touchedSince = $this->cycleStartedAt !== ''
            ? Carbon::parse($this->cycleStartedAt)
            : now()->startOfDay();

        // Mesmo teto da drenagem, e pelo mesmo motivo: um ciclo só pode
        // durar o que couber no seu retryUntil de 2h. Sem teto, a Fase 2
        // da modalidade 6 despachava os ~5.300 registros do dia (≈2,9h de
        // limitador) e, pior que morrer na cauda, mantinha a trava da
        // modalidade presa durante todo esse tempo — bloqueando a
        // drenagem exatamente na modalidade de maior volume. O que passar
        // do teto continua nulo e entra na próxima janela, em ≤20 min.
        $records = $contratacoes->listPendingDocuments(
            (int) $this->dto->codigoModalidadeContratacao,
            (int) config('pncp.pending_documents_max_per_run', 200),
            $touchedSince
        );

        if (empty($records))
        {
            // Log::info('PNCP_DOCUMENTOS_EMPTY', [
            //     'source'           => 'fase2',
            //     'modalidade'       => $this->dto->codigoModalidadeContratacao,
            //     'dataInicial'      => $this->dto->dataInicial,
            //     'dataFinal'        => $this->dto->dataFinal,
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

        // Trava por modalidade: se a varredura de pendentes (ou outro ciclo
        // desta própria Fase 2, caso de corrida) já estiver ativa para esta
        // modalidade, desiste do despacho em vez de duplicar chamadas HTTP
        // para os mesmos registros. Ver PncpSyncCycleTracker::tryStartModalidade.
        if (! $cycleTracker->tryStartModalidade($this->dto->codigoModalidadeContratacao, $syncId))
        {
            Log::warning('PNCP_DOCUMENTOS_SKIPPED_COLLISION', [
                'source'         => 'fase2',
                'modalidade'     => $this->dto->codigoModalidadeContratacao,
                'dataInicial'    => $this->dto->dataInicial,
                'dataFinal'      => $this->dto->dataFinal,
                'total_records'  => $totalRecords,
                'active_sync_id' => $cycleTracker->activeSyncId($this->dto->codigoModalidadeContratacao),
                'reason'         => 'Já existe um ciclo de documentos ativo para esta modalidade (Fase 2 ou varredura de pendentes). Registros continuam com documentos_sincronizados_em nulo; serão retomados no próximo ciclo que conseguir a trava.',
            ]);

            return;
        }

        $cycleTracker->start($syncId, $totalLotes, $totalRecords);

        Log::info('PNCP_DOCUMENTOS_STARTED', [
            'sync_id'       => $syncId,
            'source'        => 'fase2',
            'modalidade'    => $this->dto->codigoModalidadeContratacao,
            'dataInicial'   => $this->dto->dataInicial,
            'dataFinal'     => $this->dto->dataFinal,
            'total_records' => $totalRecords,
            'total_lotes'   => $totalLotes,
            'started_at'    => $startedAt->toIso8601String(),
        ]);

        foreach ($lotes as $lote)
        {
            ProcessDocumentoPageJob::dispatch(
                $lote,
                $syncId,
                $this->dto->codigoModalidadeContratacao,
                $startedAt->toIso8601String()
            );
        }
    }

    /**
     * Chamado pelo Laravel quando o job é descartado permanentemente (ex.:
     * retryUntil expirou). Sem isso, o abandono da Fase 2 de uma modalidade
     * some no log genérico de MaxAttemptsExceeded, sem contexto nenhum.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PNCP_DOCUMENTS_PHASE_ABANDONED', [
            'modalidade'  => $this->dto->codigoModalidadeContratacao,
            'dataInicial' => $this->dto->dataInicial,
            'dataFinal'   => $this->dto->dataFinal,
            'exception'   => get_class($exception),
            'message'     => $exception->getMessage(),
            'reason'      => 'Job Mestre da Fase 2 esgotou o retryUntil antes de conseguir descobrir/despachar os lotes pendentes. Os registros continuam com documentos_sincronizados_em nulo e serão retentados no próximo ciclo diário.',
        ]);
    }
}
