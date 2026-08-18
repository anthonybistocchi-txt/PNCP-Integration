<?php

namespace App\Console\Commands;

use App\DTOs\PublicContratations\GetPublicContratationsDTO;
use App\Jobs\PublicContratations\SyncPncpDataJob;
use Illuminate\Console\Command;

class SyncPncpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pncp:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispara o Job de sincronização com o PNCP para alimentar o banco de dados local.';

    private $modalidadeId = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]; // Leilão, Diálogo Competitivo, Concurso, Concorrência, Concurso / Chamamento Público, Pregão, Dispensa de Licitação, Inexigibilidade, Credenciamento, Pré-qualificação

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando disparo do Job de Sincronização do PNCP...');

        $dataInicial = now()->yesterday()->format('Ymd');
        $dataFinal   = now()->yesterday()->format('Ymd');

        foreach ($this->modalidadeId as $modalidade) 
        {
            $dto = new GetPublicContratationsDTO(
                dataInicial: $dataInicial,
                dataFinal: $dataFinal,
                codigoModalidadeContratacao: $modalidade,
                uf: null,
                codigoMunicipioIbge: null,
                pagina: 1,
                tamanhoPagina: 50
            );

            SyncPncpDataJob::dispatch($dto);

            $this->info("Job enviado para a fila: Sincronizando licitações do PNCP de {$dataInicial} até {$dataFinal}.");
        }
    }
}
