<?php

namespace App\Repositories\Contratacoes;

use App\Models\Contratacoes\CicloContratacao;
use Illuminate\Support\Collection;

class CicloContratacaoRepository
{
    /**
     * Um registro por combinação distinta modalidade + intervalo.
     *
     * O uniqueId do SyncContratacoesJob colapsa duplicatas do mesmo par.
     * Só entram ciclos em gap com itens ainda faltando.
     *
     * @return Collection<int, CicloContratacao>
     */
    public function intervalosIncompletos(int $retryHours): Collection
    {
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
