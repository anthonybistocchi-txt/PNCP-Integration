<?php

namespace App\Actions\Pncp;

use App\DTOs\Pncp\PncpOrgaoUnidadeData;
use App\Models\Pncp\PncpOrgaoUnidade;
use Illuminate\Support\Facades\DB;

class SyncPncpOrgaoUnidadesAction
{
    /**
     * @param  array<PncpOrgaoUnidadeData>  $unidadesData
     */
    public function handle(array $unidadesData): void
    {
        if (empty($unidadesData)) {
            return;
        }

        $now = now();
        $upsertData = [];

        foreach ($unidadesData as $data) {
            $upsertData[] = [
                'cnpj_orgao' => $data->cnpjOrgao,
                'ano_pca' => $data->anoPca,
                'codigo_unidade' => $data->codigoUnidade,
                'nome_unidade' => $data->nomeUnidade,
                'uf_sigla' => $data->ufSigla,
                'codigo_ibge' => $data->codigoIbge,
                'nome_municipio' => $data->nomeMunicipio,
                'poder' => $data->poder,
                'esfera' => $data->esfera,
                'ativo' => true,
                'payload_completo' => json_encode($data->payloadCompleto),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($upsertData) {
            PncpOrgaoUnidade::upsert(
                $upsertData,
                ['cnpj_orgao', 'ano_pca', 'codigo_unidade'],
                [
                    'nome_unidade',
                    'uf_sigla',
                    'codigo_ibge',
                    'nome_municipio',
                    'poder',
                    'esfera',
                    'payload_completo',
                    'updated_at',
                ]
            );
        });
    }
}
