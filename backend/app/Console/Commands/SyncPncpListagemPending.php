<?php

namespace App\Console\Commands;

use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\Jobs\PublicContratations\SyncPncpDataJob;
use App\Models\PublicContratations\PncpListagemCiclo;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reprocessa gaps de listagem (pncp_listagem_ciclos): abandoned, finished abaixo
 * do anunciado, ou started além do retryUntil. Complementa o termômetro
 * pncp:listagem-gaps disparando SyncPncpDataJob por intervalo/modalidade.
 */
class SyncPncpListagemPending extends Command
{
    protected $signature = 'pncp:sync-listagem-faltantes';

    protected $description = 'Reprocessa ciclos de listagem PNCP incompletos (gaps em pncp_listagem_ciclos).';

    public function handle(): int
    {
        $ciclos = $this->getCiclosIncompletos();

        if ($ciclos->isEmpty()) {
            $this->info('Nenhum ciclo incompleto encontrado.');

            return self::SUCCESS;
        }

        $this->info("Reprocessando {$ciclos->count()} intervalo(s) incompleto(s).");

        foreach ($ciclos as $ciclo) {
            $dto = new ListPublicContratationsDTO(
                dataInicial: $ciclo->data_inicial,
                dataFinal: $ciclo->data_final,
                codigoModalidadeContratacao: (int) $ciclo->modalidade,
                uf: null,
                codigoMunicipioIbge: null,
                cursor: null,
                limit: 50,
            );

            SyncPncpDataJob::dispatch($dto);

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
     * SyncPncpDataJob colapsa duplicatas do mesmo par).
     *
     * @return Collection<int, PncpListagemCiclo>
     */
    private function getCiclosIncompletos(): Collection
    {
        $retryHours = (int) config('pncp.retry_until_hours.listagem', 12);

        return PncpListagemCiclo::query()
            ->emGap($retryHours)
            ->select('modalidade', 'data_inicial', 'data_final')
            ->distinct()
            ->whereRaw('total_anunciado - items_processados > 0')
            ->orderBy('data_inicial')
            ->orderBy('modalidade')
            ->get();
    }
}
