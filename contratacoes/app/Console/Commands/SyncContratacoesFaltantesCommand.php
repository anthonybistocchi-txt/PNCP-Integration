<?php

namespace App\Console\Commands;

use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\Jobs\Contratacoes\SyncContratacoesJob;
use App\Models\Contratacoes\CicloContratacao;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reprocessa gaps de contratacoes (ciclos_contratacao): abandoned, finished abaixo
 * do anunciado, ou started além do retryUntil. Complementa o termômetro
 * ciclos_contratacao disparando SyncContratacoesJob por intervalo/modalidade.
 */
class SyncContratacoesFaltantesCommand extends Command
{
    protected $signature = 'pncp:sync-contratacoes-faltantes';

    protected $description = 'Reprocessa ciclos de contratacoes PNCP incompletos (gaps em ciclos_contratacao).';

    public function handle(): int
    {
        $ciclos = $this->getCiclosIncompletos();

        if ($ciclos->isEmpty())
        {
            $this->info('Nenhum ciclo incompleto encontrado.');

            return self::SUCCESS;
        }

        $this->info("Reprocessando {$ciclos->count()} intervalo(s) incompleto(s).");

        foreach ($ciclos as $ciclo)
        {
            $dto = new ListContratacoesDTO(
                dataInicial:                 $ciclo->data_inicial,
                dataFinal:                   $ciclo->data_final,
                codigoModalidadeContratacao: (int) $ciclo->modalidade,
                uf:                          null,
                codigoMunicipioIbge:         null,
                cursor:                      null,
                limit:                       50,
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

    /**
     * Um job por combinação distinta modalidade + intervalo (o uniqueId do
     * SyncContratacoesJob colapsa duplicatas do mesmo par).
     *
     * @return Collection<int, CicloContratacao>
     */
    private function getCiclosIncompletos(): Collection
    {
        $retryHours = (int) config('pncp.retry_until_hours.contratacoes', 12);

        return CicloContratacao::query()
            ->emGap($retryHours)
            ->select('modalidade', 'data_inicial', 'data_final')
            ->distinct()
            ->whereRaw('total_anunciado - itens_processados > 0')
            ->orderBy('data_inicial')
            ->orderBy('modalidade')
            ->get();
    }
}
