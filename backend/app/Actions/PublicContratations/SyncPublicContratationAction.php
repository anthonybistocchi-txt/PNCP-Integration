<?php

namespace App\Actions\PublicContratations;

use App\Models\PublicContratations\PublicContratation;
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

        try
        {
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
        catch (\Throwable $error)
        {
            Log::error(json_encode([
                'error_type'   => 'PNCP_UPSERT_DB_ERROR',
                'reason'       => 'Falha ao executar o Bulk Upsert no banco de dados.',
                'message'      => $error->getMessage(),
                'batch_size'   => count($upsertData),
                'sample_key'   => $upsertData[0]['numero_controle_pncp'] ?? 'N/A',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            throw $error;
        }
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
