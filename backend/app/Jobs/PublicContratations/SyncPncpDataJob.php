<?php

namespace App\Jobs\PublicContratations;

use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Integrations\PNCP\PncpListagemCicloRecorder;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Support\ErrorHandling\PncpFailureHandler;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncPncpDataJob implements ShouldQueue, ShouldBeUnique
{
    // Dá 5 minutos (300 segundos) de tolerância antes de matar o Job
    public $timeout = 300;

    // Atrasos para retentativas de falhas que não são da API do PNCP
    // (deadlock de banco, por exemplo). Falha de API não passa por aqui:
    // vira release com a pausa que o circuit breaker determinar.
    public $backoff = [120, 300, 600];

    // Precisa cobrir a janela do retryUntil (agora 12h para a listagem),
    // senão o lock de unicidade expira enquanto o job ainda está esperando o
    // circuit breaker. Continua morrendo bem antes do ciclo seguinte, porque
    // o cron é diário às 00:45 (00:45 + 12h = 12:45).
    public int $uniqueFor = 43200; // 12 horas

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * sync_id gravado após o START, para o failed() conseguir abandonar o ciclo
     * se o mestre morrer depois de persistir a linha e antes/durante o despacho.
     */
    protected string $syncId = '';

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ListPublicContratationsDTO $dto
    ) {}

    /**
     * Unique por modalidade + intervalo. Sem isso, 10 modalidades no mesmo
     * comando colapsam num único job (lock padrão é o nome da classe).
     */
    public function uniqueId(): string
    {
        return implode(':', [
            $this->dto->codigoModalidadeContratacao,
            $this->dto->dataInicial,
            $this->dto->dataFinal,
        ]);
    }

    /**
     * Limite por tempo em vez de por tentativas.
     *
     * release() incrementa o contador de tentativas no Laravel, então um job
     * que apenas espera o circuit breaker morreria de MaxAttemptsExceeded sem
     * ter falhado por conta própria. Com retryUntil definido o $tries deixa de
     * valer e quem manda é o relógio.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours.listagem', 12));
    }

    /**
     * Execute the job.
     */
    public function handle(
        PncpClient $client,
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        PncpSyncCycleTracker $cycleTracker,
        PncpListagemCicloRecorder $cicloRecorder
    ): void {
        Log::withContext([
            'job_id' => $this->job ? $this->job->getJobId() : 'master',
        ]);

        $startedAt = now();

        if ($failureHandler->shouldWait($this, 'Job Mestre', PncpCircuitBreaker::SCOPE_LISTAGEM)) {
            return;
        }

        $tamanhoPagina = 50; // Máximo aceito da api do PNCP

        // Faz apenas UMA requisição para a primeira página para descobrir o Total de Páginas
        $queryParams = array_filter([
            'dataInicial'                 => $this->dto->dataInicial,
            'dataFinal'                   => $this->dto->dataFinal,
            'codigoModalidadeContratacao' => $this->dto->codigoModalidadeContratacao,
            'uf'                          => $this->dto->uf,
            'codigoMunicipioIbge'         => $this->dto->codigoMunicipioIbge,
            'pagina'                      => 1,
            'tamanhoPagina'               => $tamanhoPagina,
        ]);

        try {
            $response = $client->getContratacoes($queryParams);

            // A API respondeu: qualquer pausa em curso pode ser encerrada.
            $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_LISTAGEM);

            if (! $response || empty($response->data)) {
                $cicloRecorder->recordEmpty(
                    $this->dto->codigoModalidadeContratacao,
                    $this->dto->dataInicial,
                    $this->dto->dataFinal,
                    $startedAt
                );

                Log::info('PNCP_LISTAGEM_FINISHED', [
                    'modalidade'       => $this->dto->codigoModalidadeContratacao,
                    'dataInicial'      => $this->dto->dataInicial,
                    'dataFinal'        => $this->dto->dataFinal,
                    'status'           => 'empty',
                    'total_records'    => 0,
                    'duration_seconds' => $startedAt->diffInSeconds(now()),
                ]);

                return;
            }

            $totalPaginas  = (int) ($response->totalPaginas ?? 1);
            $totalRecords  = (int) ($response->totalRegistros ?? 0);
            $this->syncId  = (string) Str::uuid();

            $cycleTracker->start($this->syncId, $totalPaginas, $totalRecords);
            $cicloRecorder->start(
                $this->syncId,
                $this->dto->codigoModalidadeContratacao,
                $this->dto->dataInicial,
                $this->dto->dataFinal,
                $totalRecords,
                $totalPaginas,
                $startedAt
            );

            Log::info('PNCP_LISTAGEM_STARTED', [
                'sync_id'       => $this->syncId,
                'modalidade'    => $this->dto->codigoModalidadeContratacao,
                'dataInicial'   => $this->dto->dataInicial,
                'dataFinal'     => $this->dto->dataFinal,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPaginas,
                'started_at'    => $startedAt->toIso8601String(),
            ]);

            $staggerSeconds = max(0, (int) config('pncp.page_stagger_seconds', 0));

            for ($pagina = 1; $pagina <= $totalPaginas; $pagina++) {
                $job = ProcessPncpPageJob::dispatch(
                    $this->dto,
                    $pagina,
                    $tamanhoPagina,
                    $this->syncId,
                    $startedAt->toIso8601String()
                );

                if ($staggerSeconds > 0) {
                    $job->delay(now()->addSeconds(($pagina - 1) * $staggerSeconds));
                }
            }
        } catch (\Throwable $error) {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_LISTAGEM, [
                'action'      => 'SyncPncpDataJob',
                'attempt'     => $this->attempts(),
                'modalidade'  => $this->dto->codigoModalidadeContratacao,
                'dataInicial' => $this->dto->dataInicial,
                'dataFinal'   => $this->dto->dataFinal,
            ]);
        }
    }

    /**
     * Mestre descartado (retryUntil). Se o START já tinha sido gravado e o
     * ciclo não fechou, marca abandoned para ficar queryável em pncp_listagem_ciclos.
     */
    public function failed(\Throwable $exception): void
    {
        if ($this->syncId === '') {
            return;
        }

        app(PncpListagemCicloRecorder::class)->abandon(
            $this->syncId,
            'SyncPncpDataJob esgotou o retryUntil: ' . $exception->getMessage()
        );
    }
}
