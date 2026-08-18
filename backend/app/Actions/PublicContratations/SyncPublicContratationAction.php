<?php

namespace App\Actions\PublicContratations;

use App\Models\PublicContratation;
use Illuminate\Support\Facades\Log;

class SyncPublicContratationAction
{
    /**
     * Recebe um lote (array) de itens da API do PNCP e processa um bulk upsert.
     */
    public function handle(array $pncpItems): void
    {
        if (empty($pncpItems)) 
        {
            return;
        }

        $upsertData = [];

        foreach ($pncpItems as $pncpItem) 
        {
            $numeroControle = $pncpItem->numeroControlePNCP ?? null;

            if (!$numeroControle) 
            {
                continue;
            }

            $upsertData[] = $this->mapData($pncpItem);
        }

        if (empty($upsertData)) 
        {
            return;
        }

        // Executa o Bulk Upsert:
        // Parâmetro 1: Array associativo com os dados.
        // Parâmetro 2: A coluna(s) que identifica(m) um registro único (Primary Key).
        // Parâmetro 3: Quais colunas devem ser atualizadas se o registro já existir.
        PublicContratation::upsert(
            $upsertData,
            ['numero_controle_pncp'],
            [
                'orgao_cnpj',
                'ano_compra',
                'data_publicacao_pncp',
                'data_atualizacao_global',
                'uf',
                'codigo_municipio_ibge',
                'codigo_modalidade_contratacao',
                'situacao_compra_id',
                'valor_total_estimado',
                'data_abertura_proposta',
                'data_encerramento_proposta',
                'srp',
                'objeto_compra',
                'payload_completo',
                'documentos',
            ]
        );

        Log::info("PNCP Sync: Bulk Upserted " . count($upsertData) . " records.");
    }

    private function mapData(object $data): array
    {
        return [
            'numero_controle_pncp'          => $data->numeroControlePNCP,
            'orgao_cnpj'                    => $data->orgaoEntidade->cnpj ?? null,
            'ano_compra'                    => $data->anoCompra ?? null,
            'situacao_compra_id'            => $data->situacaoCompraId ?? null,
            'valor_total_estimado'          => isset($data->valorTotalEstimado) ? (int) round($data->valorTotalEstimado * 100) : null,
            'data_abertura_proposta'        => !empty($data->dataAberturaProposta) ? date('Y-m-d H:i:s', strtotime($data->dataAberturaProposta)) : null,
            'data_publicacao_pncp'          => $data->dataPublicacaoPncp ? date('Y-m-d H:i:s', strtotime($data->dataPublicacaoPncp)) : null,
            'data_atualizacao_global'       => ($data->dataAtualizacaoGlobal ?? $data->dataAtualizacao) ? date('Y-m-d H:i:s', strtotime($data->dataAtualizacaoGlobal ?? $data->dataAtualizacao)) : null,
            'uf'                            => $data->unidadeOrgao->ufSigla ?? null,
            'codigo_municipio_ibge'         => $data->unidadeOrgao->codigoIbge ?? null,
            'codigo_modalidade_contratacao' => $data->modalidadeId ?? null,
            'data_encerramento_proposta'    => !empty($data->dataEncerramentoProposta) ? date('Y-m-d H:i:s', strtotime($data->dataEncerramentoProposta)) : null,
            'srp'                           => $data->srp ?? false,
            'objeto_compra'                 => $data->objetoCompra ?? null,
            // No upsert em massa, arrays não passam pelo mutator cast automático de todos os drivers, 
            // então garantimos o json_encode aqui.
            'payload_completo'              => json_encode($data),
            'documentos'                    => json_encode($data->documentos ?? []),
        ];
    }
}
