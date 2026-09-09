<?php

namespace App\Jobs\PublicContratations;

use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Models\PublicContratations\PublicContratation;
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
 * processados (documents_fetched_at IS NULL).
 *
 * É disparado automaticamente pelo ProcessPncpPageJob quando o ciclo de
 * listagem de uma modalidade é concluído. Não requer cron adicional.
 */
class SyncPncpDocumentsJob implements ShouldQueue, ShouldBeUnique
{
    // Dá 5 minutos de tolerância para descobrir os registros e enfileirar os workers.
    public $timeout = 300;

    // Cobre a janela do retryUntil, senão o lock de unicidade expira
    // enquanto o job ainda está aguardando.
    public int $uniqueFor = 21600; // 6 horas

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ListPublicContratationsDTO $dto,
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
        return now()->addHours((int) config('pncp.retry_until_hours', 6));
    }

    /**
     * Execute the job.
     */
    public function handle(PncpSyncCycleTracker $cycleTracker): void
    {
        $startedAt = now();

        // A listagem consulta /contratacoes/atualizacao, então o ciclo traz
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

        // Seleciona apenas as colunas necessárias para a chamada HTTP.
        $records = PublicContratation::select([
                'numero_controle_pncp',
                'orgao_cnpj',
                'ano_compra',
                'sequencial_compra',
            ])
            ->whereNull('documents_fetched_at')
            ->where('codigo_modalidade_contratacao', $this->dto->codigoModalidadeContratacao)
            ->where('updated_at', '>=', $touchedSince)
            ->whereNotNull('sequencial_compra')
            ->get()
            ->toArray();

        if (empty($records)) {
            Log::info('PNCP_DOCUMENTOS_FINISHED', [
                'source'           => 'fase2',
                'modalidade'       => $this->dto->codigoModalidadeContratacao,
                'dataInicial'      => $this->dto->dataInicial,
                'dataFinal'        => $this->dto->dataFinal,
                'status'           => 'empty',
                'total_records'    => 0,
                'duration_seconds' => $startedAt->diffInSeconds(now()),
            ]);

            return;
        }

        $lotes       = array_chunk($records, 50);
        $totalLotes  = count($lotes);
        $totalRecords = count($records);
        $syncId      = (string) Str::uuid();

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

        foreach ($lotes as $lote) {
            ProcessPncpDocumentPageJob::dispatch(
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
            'reason'      => 'Job Mestre da Fase 2 esgotou o retryUntil antes de conseguir descobrir/despachar os lotes pendentes. Os registros continuam com documents_fetched_at nulo e serão retentados no próximo ciclo diário.',
        ]);
    }
}
