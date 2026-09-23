<?php

namespace App\Actions\Pncp;

use App\DTOs\Pncp\PncpOrgaoUnidadeData;
use App\Models\Pncp\PncpOrgaoUnidade;
use Illuminate\Support\Facades\DB;

class SyncPncpOrgaoUnidadesAction
{
    /**
     * @param array<PncpOrgaoUnidadeData> $unidadesData
     */
    public function handle(array $unidadesData): void
    {
        if (empty($unidadesData)) {
            return;
        }

        $upsertData = [];
        foreach ($unidadesData as $data) {
            $upsertData[] = [
                'cnpj_orgao' => $data->cnpjOrgao,
                'codigo_unidade' => $data->codigoUnidade,
                'nome_unidade' => $data->nomeUnidade,
                'uf_sigla' => $data->ufSigla,
                'codigo_ibge' => $data->codigoIbge,
                'nome_municipio' => $data->nomeMunicipio,
                'poder_id' => $data->poderId,
                'esfera_id' => $data->esferaId,
                'payload_completo' => json_encode($data->payloadCompleto),
            ];
        }

        DB::transaction(function () use ($upsertData) {
            PncpOrgaoUnidade::upsert(
                $upsertData,
                ['cnpj_orgao', 'codigo_unidade'],
                [
                    'nome_unidade',
                    'uf_sigla',
                    'codigo_ibge',
                    'nome_municipio',
                    'poder_id',
                    'esfera_id',
                    'payload_completo',
                ]
            );
        });
    }
}
