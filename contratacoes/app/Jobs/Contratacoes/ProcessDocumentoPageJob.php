<?php

namespace App\Jobs\Contratacoes;

use App\Actions\Contratacoes\SyncContratacaoDocumentosAction;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Models\Contratacoes\Contratacao;
use App\Support\ErrorHandling\PncpFailureHandler;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Worker da Fase 2: recebe um lote de licitações e busca os documentos
 * (editais, anexos) de cada uma, persistindo o resultado de forma atômica.
 *
 * Compartilha o mesmo PncpCircuitBreaker e PncpFailureHandler da Fase 1,
 * garantindo que instabilidade do PNCP interrompa apenas os documentos,
 * sem impactar o ciclo de contratacoes do dia seguinte.
 *
 * Idempotência: o upsert parcial (apenas documentos + documentos_sincronizados_em)
 * garante que reprocessamentos não corrompam os dados da Fase 1.
 */
class ProcessDocumentoPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Pior caso por item: retries × document_timeout + retry_sleep_ms
    // (2 × 30s + 3s = 63s). Com documents_lote_size = 10 dá 630s, e os 900s
    // ainda cobrem a espera do limitador global e a escrita no banco.
    // Refaça essa conta ao mexer no lote ou no timeout de documentos: passar
    // de 900s faz o worker matar o job no meio do lote, e passar do
    // REDIS_QUEUE_RETRY_AFTER (960s) faz a fila entregar o lote em duplicata.
    public $timeout = 900;

    // Atrasos para falhas que não são da API do PNCP (deadlock de banco, etc.).
    // Falha de API vira release com a pausa do circuit breaker.
    public $backoff = [120, 300, 600, 1200, 2400];

    /**
     * Create a new job instance.
     *
     * @param array<array{
     *     numero_controle_pncp: string,
     *     orgao_cnpj: string,
     *     ano_compra: int,
     *     sequencial_compra: int
     * }> $items Lote de licitações para buscar documentos.
     */
    public function __construct(
        protected array $items,
        protected string $syncId = '',
        protected int $modalidade = 0,
        protected string $cycleStartedAt = ''
    ) {}

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
    public function handle(
        PncpClient $client,
        SyncContratacaoDocumentosAction $syncAction,
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        PncpSyncCycleTracker $cycleTracker
    ): void {

        Log::withContext([
            'sync_id'    => $this->syncId,
            'job_id'     => $this->job ? $this->job->getJobId() : 'documents',
            'modalidade' => $this->modalidade,
            'lote_size'  => count($this->items),
        ]);

        if ($failureHandler->shouldWait($this, 'Job de Documentos', PncpCircuitBreaker::SCOPE_DOCUMENTOS))
        {
            return;
        }

        // Retentativa não recomeça do zero: só busca o que ainda está nulo.
        $pendentes = $this->itemsAindaPendentes();
        $jaFeitos  = count($this->items) - count($pendentes);

        if ($pendentes === [])
        {
            $this->markCyclePageDone($cycleTracker, $jaFeitos);

            return;
        }

        $upsertData = [];

        try
        {
            foreach ($pendentes as $item)
            {
                $cnpj       = $item['orgao_cnpj'];
                $ano        = $item['ano_compra'];
                $sequencial = $item['sequencial_compra'];

                $documentos = $client->getArquivos($cnpj, $ano, $sequencial);

                // Registra sucesso parcial para manter o circuit breaker atualizado.
                // Em caso de falha abaixo do limiar, o breaker não abre ainda.
                $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_DOCUMENTOS);

                $upsertData[] = [
                    'numero_controle_pncp'        => $item['numero_controle_pncp'],
                    'documentos'                  => json_encode($documentos),
                    'documentos_sincronizados_em' => now()->toDateTimeString(),
                ];
            }

            // Persiste o lote inteiro de uma vez (1 query para N itens).
            $syncAction->handle($upsertData);

            $this->markCyclePageDone($cycleTracker, count($upsertData) + $jaFeitos);

        }
        catch (\Throwable $error)
        {
            // Grava o que já veio antes de devolver o lote para a fila.
            //
            // O lote era atômico: uma falha em qualquer item descartava o
            // $upsertData inteiro. Como o `documentos_sincronizados_em` de cada
            // registro é independente, essa atomicidade não protegia nada e
            // custava caro — com 30% de falha por item, a chance de um lote de
            // 50 fechar era 0,7^50 ≈ 0,00002%, então na prática toda tentativa
            // rebuscava tudo e morria de novo, queimando vaga do limitador
            // global em registros que já tinham sido obtidos e jogados fora.
            // É a origem dos `attempt: 31..51` de 15/09.

            if ($upsertData !== [])
            {
                $syncAction->handle($upsertData);
            }

            // Reagendado (API indisponível ou em pausa): o lote ainda vai rodar,
            // então o contador do ciclo de documentos não pode avançar.
            if ($failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_DOCUMENTOS, [
                'action'      => 'ProcessDocumentoPageJob',
                'lote_size'   => count($this->items),
                'pendentes'   => count($pendentes),
                'persistidos' => count($upsertData),
                'attempt'     => $this->attempts(),
                'modalidade'  => $this->modalidade,
            ]))
            {
                return;
            }

            // Só chega aqui quando a falha é permanente/crítica (não haverá retry).
            $this->markCyclePageDone($cycleTracker, count($upsertData) + $jaFeitos);
        }
    }

    /**
     * Itens do lote que ainda estão com `documentos_sincronizados_em` nulo.
     *
     * Cobre dois caminhos: a retentativa deste próprio lote (que já persistiu
     * parte dos itens na tentativa anterior) e a sobreposição entre a Fase 2 e
     * a drenagem de pendentes — a trava por modalidade impede os dois ciclos
     * ativos ao mesmo tempo, mas não os lotes de um ciclo já morto que ainda
     * estavam enfileirados quando o ciclo seguinte pegou os mesmos registros.
     *
     * @return list<array{numero_controle_pncp: string, orgao_cnpj: string, ano_compra: int, sequencial_compra: int}>
     */
    private function itemsAindaPendentes(): array
    {
        $jaBuscados = Contratacao::query()
            ->whereIn('numero_controle_pncp', array_column($this->items, 'numero_controle_pncp'))
            ->whereNotNull('documentos_sincronizados_em')
            ->pluck('numero_controle_pncp')
            ->all();

        if ($jaBuscados === [])
        {
            return array_values($this->items);
        }

        $jaBuscados = array_flip($jaBuscados);

        return array_values(array_filter(
            $this->items,
            fn (array $item) => ! isset($jaBuscados[$item['numero_controle_pncp']])
        ));
    }

    /**
     * Incrementa o contador do ciclo de documentos.
     * A última página emite PNCP_DOCUMENTOS_FINISHED.
     */
    private function markCyclePageDone(PncpSyncCycleTracker $cycleTracker, int $itemsProcessed): void
    {
        $summary = $cycleTracker->markPageDone($this->syncId, $itemsProcessed);

        if ($summary === null)
        {
            return;
        }

        // Libera a trava de modalidade (ver PncpSyncCycleTracker::tryStartModalidade)
        // assim que o ciclo termina, para que o próximo ciclo agendado (Fase 2
        // do dia seguinte ou a próxima varredura de pendentes) já possa rodar.
        $cycleTracker->finishModalidade($this->modalidade, $this->syncId);

        $finishedAt = now();
        $startedAt  = $this->cycleStartedAt !== '' ? Carbon::parse($this->cycleStartedAt) : null;

        Log::info('PNCP_DOCUMENTOS_FINISHED', [
            'sync_id'          => $this->syncId,
            'modalidade'       => $this->modalidade,
            'status'           => 'ok',
            'total_records'    => $summary['total_records'],
            'items_processed'  => $summary['items_processed'],
            'lotes_completed'  => $summary['units_completed'],
            'duration_seconds' => $startedAt ? $startedAt->diffInSeconds($finishedAt) : null,
            'finished_at'      => $finishedAt->toIso8601String(),
        ]);
    }

    /**
     * Chamado pelo Laravel quando o job é descartado permanentemente (ex.:
     * retryUntil expirou durante uma degradação prolongada do PNCP). Sem
     * isso, o abandono de um lote de documentos some no log genérico de
     * MaxAttemptsExceeded, sem contexto de modalidade/lote nenhum.
     *
     * Também conta o lote no ciclo (0 itens): o handle() não roda nesse
     * caminho, então sem markPageDone o contador nunca chega em total e a
     * trava da modalidade fica presa até o TTL — a varredura de pendentes
     * via SKIPPED_COLLISION mesmo com o ciclo já morto (caso da modalidade 9
     * em 12/09).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PNCP_DOCUMENTS_PHASE_ABANDONED', [
            'sync_id'    => $this->syncId,
            'modalidade' => $this->modalidade,
            'lote_size'  => count($this->items),
            'exception'  => get_class($exception),
            'message'    => $exception->getMessage(),
            'reason'     => 'Lote de documentos esgotou o retryUntil sem conseguir buscar os arquivos. Os itens que já tinham sido buscados ficaram persistidos; o que sobrou continua com documentos_sincronizados_em nulo e entra na próxima janela da drenagem contínua.',
        ]);

        $this->markCyclePageDone(app(PncpSyncCycleTracker::class), 0);
    }
}
