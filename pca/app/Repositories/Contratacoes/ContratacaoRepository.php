<?php

namespace App\Repositories\Contratacoes;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContratacaoRepository
{
    /**
     * Mapa codigoUnidade → UF/IBGE/município a partir do payload das contratações
     * deste órgão (registro mais recente por unidade).
     *
     * getPcaUnidades() traz poder/esfera e codigo/nome, mas não traz UF/IBGE/município.
     * Esses campos vivem em unidadeOrgao das contratações.
     *
     * @return Collection<string, object{uf_sigla: ?string, codigo_ibge: ?string, nome_municipio: ?string}>
     */
    public function geoByCodigoUnidade(string $cnpj): Collection
    {
        return DB::table('contratacoes')
            ->selectRaw("
                DISTINCT ON (payload_completo->'unidadeOrgao'->>'codigoUnidade')
                payload_completo->'unidadeOrgao'->>'codigoUnidade' AS codigo_unidade,
                payload_completo->'unidadeOrgao'->>'ufSigla' AS uf_sigla,
                payload_completo->'unidadeOrgao'->>'codigoIbge' AS codigo_ibge,
                payload_completo->'unidadeOrgao'->>'municipioNome' AS nome_municipio
            ")
            ->where('orgao_cnpj', $cnpj)
            ->whereRaw("payload_completo->'unidadeOrgao'->>'codigoUnidade' IS NOT NULL")
            ->orderByRaw("payload_completo->'unidadeOrgao'->>'codigoUnidade'")
            ->orderByRaw('data_atualizacao_global DESC NULLS LAST')
            ->get()
            ->keyBy('codigo_unidade');
    }
}
