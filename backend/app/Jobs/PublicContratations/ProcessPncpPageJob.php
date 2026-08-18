<?php

namespace App\Jobs\PublicContratations;

use App\Actions\PublicContratations\SyncPublicContratationAction;
use App\DTOs\PublicContratations\GetPublicContratationsDTO;
use App\Integrations\PNCP\PncpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\RequestException;

class ProcessPncpPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Aumentado o timeout para 10 minutos para acomodar o usleep no processamento de até 50 itens
    public $timeout = 600;

    // Se a API do governo cair, ele tenta rodar esse job até 5 vezes
    public $tries = 5;

    // Se o Job falhar (ex: Too Many Requests), ele não tenta de novo na mesma hora.
    // O Job vai esperar 2 minutos na 1ª falha, 5 minutos na 2ª, 10 minutos na 3ª, 20 minutos na 4ª e 40 minutos na 5ª.
    public $backoff = [120, 300, 600, 1200, 2400];

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected GetPublicContratationsDTO $dto,
        protected int $pagina,
        protected int $tamanhoPagina
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PncpClient $client, SyncPublicContratationAction $syncAction): void
    {
        // 1. Verificação global do Circuit Breaker antes de qualquer requisição
        if (Cache::has('pncp_circuit_breaker_until')) 
        {
            $pauseUntil = Cache::get('pncp_circuit_breaker_until');
            $remainingSeconds = $pauseUntil - now()->timestamp;
            
            if ($remainingSeconds > 0) 
            {
                Log::info("Circuit Breaker ativo. Devolvendo Job da página {$this->pagina} para a fila com atraso de {$remainingSeconds}s.");
                $this->release($remainingSeconds);
                return;
            }
        }

        Log::info("ProcessPncpPageJob started for page {$this->pagina}.");

        $queryParams = array_filter([
            'dataInicial'                 => $this->dto->dataInicial,
            'dataFinal'                   => $this->dto->dataFinal,
            'codigoModalidadeContratacao' => $this->dto->codigoModalidadeContratacao,
            'uf'                          => $this->dto->uf,
            'codigoMunicipioIbge'         => $this->dto->codigoMunicipioIbge,
            'pagina'                      => $this->pagina,
            'tamanhoPagina'               => $this->tamanhoPagina,
        ]);

        try 
        {
            $response = $client->getContratacoes($queryParams);

            if (!$response || empty($response->data)) 
            {
                Log::info("ProcessPncpPageJob finished. No data on page {$this->pagina}.");
                return;
            }

            // Para cada item da página, vamos buscar os documentos (editais, anexos) associados
            foreach ($response->data as $item) 
            {
                $cnpj       = $item->orgaoEntidade->cnpj ?? null;
                $ano        = $item->anoCompra ?? null;
                $sequencial = $item->sequencialCompra ?? null;

                if ($cnpj && $ano && $sequencial) 
                {
                    // Adiciona o array de arquivos/documentos ao payload do item
                    $item->documentos = $client->getArquivos($cnpj, $ano, $sequencial);

                    // Dorme 0.2 segundos (200.000 microssegundos) para evitar DDoS e 429 Too Many Requests
                    usleep(200000);
                } 
                else 
                {
                    $item->documentos = [];
                }
            }

            // Passa o array completo para a Action fazer o Bulk Upsert
            $syncAction->handle($response->data);

            Log::info("ProcessPncpPageJob finished. Processed " . count($response->data) . " items on page {$this->pagina}.");
            
        } 
        catch (RequestException $error) 
        {
            if ($error->response->status() === 429) 
            {
                $pauseMinutes = 5; // 5 minutos de pausa
                $pauseUntil   = now()->addMinutes($pauseMinutes)->timestamp;
                
                Cache::put('pncp_circuit_breaker_until', $pauseUntil, now()->addMinutes($pauseMinutes));
                
                Log::warning("Erro 429 (Too Many Requests) no PNCP! Ativando Circuit Breaker por {$pauseMinutes} minutos. Job da página {$this->pagina} adiado.");
                $this->release($pauseMinutes * 60);
                return;
            }
            
            // Outros erros continuam seguindo o fluxo normal de falha (usando o $backoff configurado)
            throw $error;
        }
    }
}
