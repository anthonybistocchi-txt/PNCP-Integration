<?php

namespace App\Jobs\PublicContratations;

use App\Actions\PublicContratations\SyncPublicContratationAction;
use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\Integrations\PNCP\PncpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;
use App\Jobs\PublicContratations\ProcessPncpPageJob;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpSyncCycleTracker;
use App\Support\ErrorHandling\JobExceptionPolicy;
use Illuminate\Support\Str;

class SyncPncpDataJob implements ShouldQueue, ShouldBeUnique
{
    // Dá 5 minutos (300 segundos) de tolerância antes de matar o Job
    public $timeout = 300;

    // Se a API do governo cair, ele tenta rodar esse job até 5 vezes
    public $tries = 5;

    // Atrasos para retentativas naturais de falha
    public $backoff = [120, 300, 600];

    public int $uniqueFor = 5400; // 1 hora e meia de lock para evitar duplicidade

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     * Execute the job.
     */
    public function handle(PncpClient $client, PncpCircuitBreaker $circuitBreaker, JobExceptionPolicy $jobExceptionPolicy, PncpSyncCycleTracker $cycleTracker): void
    {
        Log::withContext([
            'job_id' => $this->job ? $this->job->getJobId() : 'master',
        ]);
        
        $startedAt = now();

        // 1. Verificação global do Circuit Breaker antes de qualquer requisição
        if ($circuitBreaker->isOpen()) 
        {
            $remainingSeconds = $circuitBreaker->remainingSeconds();
            Log::info("Circuit Breaker ativo. Devolvendo Job Mestre para a fila com atraso de {$remainingSeconds}s.");
            $this->release($remainingSeconds);
            return;
        }

        Log::info('SyncPncpDataJob (MASTER) started for parameters', (array) $this->dto);

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

        try 
        {
            $response = $client->getContratacoes($queryParams);

            if (!$response || empty($response->data)) 
            {
                Log::info('SyncPncpDataJob (MASTER) finished. No records found to sync.');
                return;
            }
            $totalPaginas = $response->totalPaginas ?? 1;
            Log::info("PNCP API reports {$response->totalRegistros} total records across {$totalPaginas} pages.");

            $syncId = (string) Str::uuid();

            $cycleTracker->start($syncId, $totalPaginas);

            // Cria os N Jobs Filhos (Operários) e joga na fila, escalonando no tempo (ex: a cada 20 segundos)
            for ($pagina = 1; $pagina <= $totalPaginas; $pagina++) 
            {
                $delayInSeconds = ($pagina - 1) * 20;
                ProcessPncpPageJob::dispatch($this->dto, $pagina, $tamanhoPagina, $syncId, $startedAt->toIso8601String())
                    ->delay(now()->addSeconds($delayInSeconds));
            }

            Log::info('SYNC_PAGES_DISPATCHED', [
                'sync_id'                => $syncId,
                'modalidade'             => $this->dto->codigoModalidadeContratacao,
                'total_pages_dispatched' => $totalPaginas,
                'total_records_reported' => $response->totalRegistros,
                'started_at'             => $startedAt->toIso8601String(),
                'dto'                    => (array) $this->dto,
            ]);
            
        } 
        catch (\Throwable $error)
        {
            if ($error instanceof RequestException && $error->response->status() === 429) 
            {
                $pauseMinutes = 5; // 5 minutos de pausa
                $circuitBreaker->trip($pauseMinutes);
                
                Log::warning("Erro 429 (Too Many Requests) no PNCP! Ativando Circuit Breaker por {$pauseMinutes} minutos. Job Mestre adiado.");
                $this->release($pauseMinutes * 60);
                return;
            }
            
            $jobExceptionPolicy->handle($error, $this, [
                'action'  => 'SyncPncpDataJob',
                'attempt' => $this->attempts(),
                'dto'     => (array) $this->dto,
            ]);
        }
    }
}

