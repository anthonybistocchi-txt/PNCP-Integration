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

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando disparo do Job de Sincronização do PNCP...');

        // Em um sistema real, você buscaria do banco de dados quais cidades/UFs seus clientes assinam
        // e faria um foreach disparando um Job para cada combinação.
        // Como MVP, vamos simular a busca para Ribeirão Preto nos últimos 30 dias.
        $dataInicial = now()->subDays(17)->format('Ymd');
        $dataFinal   = now()->format('Ymd');

        $dto = new GetPublicContratationsDTO(
            dataInicial: $dataInicial,
            dataFinal: $dataFinal,
            codigoModalidadeContratacao: 6, // Pregão
            uf: 'SP',
            codigoMunicipioIbge: 3543402, // Ribeirão Preto
            pagina: 1,
            tamanhoPagina: 500
        );

        SyncPncpDataJob::dispatch($dto);

        $this->info("Job enviado para a fila: Sincronizando licitações de SP (IBGE 3543402) de {$dataInicial} até {$dataFinal}.");
    }
}
