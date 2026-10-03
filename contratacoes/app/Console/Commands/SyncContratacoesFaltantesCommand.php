<?php

namespace App\Console\Commands;

use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\Jobs\Contratacoes\SyncContratacoesJob;
use App\Repositories\Contratacoes\CicloContratacaoRepository;
use Illuminate\Console\Command;

/**
 * Reprocessa gaps de contratacoes (ciclos_contratacao): abandoned, finished abaixo
 * do anunciado, ou started além do retryUntil. Complementa o termômetro
 * ciclos_contratacao disparando SyncContratacoesJob por intervalo/modalidade.
 */
class SyncContratacoesFaltantesCommand extends Command
{
    protected $signature = 'pncp:sync-contratacoes-faltantes';

    protected $description = 'Reprocessa ciclos de contratacoes PNCP incompletos (gaps em ciclos_contratacao).';

    public function handle(CicloContratacaoRepository $cicloRepository): int
    {
        $retryHours = (int) config('pncp.retry_until_hours.contratacoes', 12);
        $ciclos     = $cicloRepository->intervalosIncompletos($retryHours);

        if ($ciclos->isEmpty())
        {
            $this->info('Nenhum ciclo incompleto encontrado.');

            return self::SUCCESS;
        }

        $this->info("Reprocessando {$ciclos->count()} intervalo(s) incompleto(s).");

        foreach ($ciclos as $ciclo)
        {
            $dto = new ListContratacoesDTO(
                dataInicial: $ciclo->data_inicial,
                dataFinal: $ciclo->data_final,
                codigoModalidadeContratacao: (int) $ciclo->modalidade,
                uf: null,
                codigoMunicipioIbge: null,
                cursor: null,
                limit: 50,
            );

            SyncContratacoesJob::dispatch($dto);

            $this->info(sprintf(
                'Job enviado: modalidade %d, %s até %s.',
                $ciclo->modalidade,
                $ciclo->data_inicial,
                $ciclo->data_final
            ));
        }

        return self::SUCCESS;
    }
}
