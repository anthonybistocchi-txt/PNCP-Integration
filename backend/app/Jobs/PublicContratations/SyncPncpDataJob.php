<?php

namespace App\Jobs\PublicContratations;

use App\Actions\PublicContratations\SyncPublicContratationAction;
use App\DTOs\PublicContratations\GetPublicContratationsDTO;
use App\Integrations\PNCP\PncpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\RequestException;
use App\Jobs\PublicContratations\ProcessPncpPageJob;

class SyncPncpDataJob implements ShouldQueue, ShouldBeUnique
{
    // Dá 5 minutos (300 segundos) de tolerância antes de matar o Job
    public $timeout = 300;

    // Se a API do governo cair, ele tenta rodar esse job até 5 vezes
    public $tries = 5;

    // Atrasos para retentativas naturais de falha
    public $backoff = [120, 300, 600];

    public int $uniqueFor = 3600; // 1 hora de lock para evitar duplicidade

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected GetPublicContratationsDTO $dto
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PncpClient $client): void
    {
        $startedAt = now();

        // 1. Verificação global do Circuit Breaker antes de qualquer requisição
        if (Cache::has('pncp_circuit_breaker_until')) 
        {
            $pauseUntil       = Cache::get('pncp_circuit_breaker_until');
            $remainingSeconds = $pauseUntil - now()->timestamp;
            
            if ($remainingSeconds > 0) 
            {
                Log::info("Circuit Breaker ativo. Devolvendo Job Mestre para a fila com atraso de {$remainingSeconds}s.");
                $this->release($remainingSeconds);
                return;
            }
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

            // Cria os N Jobs Filhos (Operários) e joga na fila, escalonando no tempo (ex: a cada 20 segundos)
            for ($pagina = 1; $pagina <= $totalPaginas; $pagina++) 
            {
                $delayInSeconds = ($pagina - 1) * 20;
                ProcessPncpPageJob::dispatch($this->dto, $pagina, $tamanhoPagina)
                    ->delay(now()->addSeconds($delayInSeconds));
            }

            $finishedAt = now();

            Log::info(json_encode([
                'event'                   => 'SYNC_CYCLE_COMPLETED',
                'modalidade'              => $this->dto->codigoModalidadeContratacao,
                'total_pages_dispatched'  => $totalPaginas,
                'total_records_processed' => $response->totalRegistros,
                'duration_minutes'        => $startedAt->diffInMinutes($finishedAt),
                'started_at'              => $startedAt->toIso8601String(),
                'finished_at'             => $finishedAt->toIso8601String(),
                'dto'                     => (array) $this->dto,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            
        } 
        catch (RequestException $error) 
        {
            if ($error->response->status() === 429) 
            {
                $pauseMinutes = 5; // 5 minutos de pausa
                $pauseUntil   = now()->addMinutes($pauseMinutes)->timestamp;
                
                Cache::put('pncp_circuit_breaker_until', $pauseUntil, now()->addMinutes($pauseMinutes));
                
                Log::warning("Erro 429 (Too Many Requests) no PNCP! Ativando Circuit Breaker por {$pauseMinutes} minutos. Job Mestre adiado.");
                $this->release($pauseMinutes * 60);
                return;
            }
            
            throw $error;
        }
        catch (\Throwable $error)
        {
            // Captura qualquer erro inesperado no Job Mestre com contexto completo
            Log::error(json_encode([
                'error_type'  => 'PNCP_UNEXPECTED_MASTER_JOB_ERROR',
                'reason'      => 'Erro inesperado no SyncPncpDataJob (MASTER). Ver campo "message" para detalhes.',
                'message'     => $error->getMessage(),
                'job_context' => [
                    'attempt' => $this->attempts(),
                    'dto'     => (array) $this->dto,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            throw $error;
        }
    }
}
