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

        Log::info('SyncPncpDocumentsJob (MASTER) started.', [
            'modalidade'  => $this->dto->codigoModalidadeContratacao,
            'dataInicial' => $this->dto->dataInicial,
            'dataFinal'   => $this->dto->dataFinal,
        ]);

        $dataInicial = Carbon::createFromFormat('Ymd', $this->dto->dataInicial)->startOfDay();
        $dataFinal   = Carbon::createFromFormat('Ymd', $this->dto->dataFinal)->endOfDay();

        // Busca todos os registros sem documentos sincronizados no intervalo,
        // selecionando apenas as colunas necessárias para a chamada HTTP.
        $records = PublicContratation::select([
                'numero_controle_pncp',
                'orgao_cnpj',
                'ano_compra',
                'sequencial_compra',
            ])
            ->whereNull('documents_fetched_at')
            ->where('codigo_modalidade_contratacao', $this->dto->codigoModalidadeContratacao)
            ->whereBetween('data_publicacao_pncp', [$dataInicial, $dataFinal])
            ->whereNotNull('sequencial_compra')
            ->get()
            ->toArray();

        if (empty($records)) {
            Log::info('SyncPncpDocumentsJob (MASTER) finished. No records pending document sync.', [
                'modalidade' => $this->dto->codigoModalidadeContratacao,
            ]);

            return;
        }

        $lotes       = array_chunk($records, 50);
        $totalLotes  = count($lotes);
        $syncId      = (string) Str::uuid();

        $cycleTracker->start($syncId, $totalLotes);

        foreach ($lotes as $lote) {
            ProcessPncpDocumentPageJob::dispatch(
                $lote,
                $syncId,
                $this->dto->codigoModalidadeContratacao,
                $startedAt->toIso8601String()
            );
        }

        Log::info('DOCUMENTS_SYNC_DISPATCHED', [
            'sync_id'         => $syncId,
            'modalidade'      => $this->dto->codigoModalidadeContratacao,
            'total_records'   => count($records),
            'total_lotes'     => $totalLotes,
            'started_at'      => $startedAt->toIso8601String(),
        ]);
    }
}
