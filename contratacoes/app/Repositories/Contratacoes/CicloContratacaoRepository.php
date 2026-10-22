<?php

namespace App\Repositories\Contratacoes;

use App\Models\Contratacoes\CicloContratacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CicloContratacaoRepository
{
    /**
     * Um registro por combinação distinta modalidade + intervalo.
     *
     * O uniqueId do SyncContratacoesJob colapsa duplicatas do mesmo par.
     * Entra gap com itens faltando, ou ciclo que morreu sem total anunciado
     * (intenção gravada, PNCP nunca respondeu). Empty não entra: o PNCP
     * confirmou que não havia registro.
     *
     * @return Collection<int, CicloContratacao>
     */
    public function intervalosIncompletos(int $retryHours): Collection
    {
        return CicloContratacao::query()
            ->emGap($retryHours)
            ->select('modalidade', 'data_inicial', 'data_final')
            ->distinct()
            ->where(function (Builder $query)
            {
                $query->whereRaw('total_anunciado - itens_processados > 0')
                    ->orWhere(function (Builder $unknown)
                    {
                        $unknown->where('total_anunciado', 0)
                            ->where('total_paginas', 0)
                            ->where('itens_processados', 0);
                    });
            })
            ->orderBy('data_inicial')
            ->orderBy('modalidade')
            ->get();
    }
}
