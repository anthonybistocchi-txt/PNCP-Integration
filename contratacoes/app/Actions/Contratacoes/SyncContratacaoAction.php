<?php

namespace App\Actions\Contratacoes;

use App\Models\Contratacoes\Contratacao;
use Illuminate\Support\Facades\Log;

class SyncContratacaoAction
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
            // `documentos` entra só no INSERT (linha nova = []). Não entra no
            // update: um reprocessamento de contratacoes (ex.: backfill --days=7)
            // não pode apagar arquivos que a Fase 2 / drenagem já gravou.
            // `documentos_sincronizados_em` também fica de fora do update pelo mesmo
            // motivo — quem mexe nele é só o fluxo de documentos.
            Contratacao::upsert(
                $upsertData,
                ['numero_controle_pncp'],
                [
                    'orgao_cnpj',
                    'ano_compra',
                    'sequencial_compra',
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
                ]
            );
        }
        catch (\Throwable $error)
        {
            Log::error('PNCP_UPSERT_DB_ERROR', [
                'reason'       => 'Falha ao executar o Bulk Upsert no banco de dados.',
                'message'      => $error->getMessage(),
                'batch_size'   => count($upsertData),
                'sample_key'   => $upsertData[0]['numero_controle_pncp'] ?? 'N/A',
            ]);

            throw $error;
        }
    }

    private function mapData(object $data): array
    {
        return [
            'numero_controle_pncp'          => $data->numeroControlePNCP,
            'orgao_cnpj'                    => $data->orgaoEntidade->cnpj ?? null,
            'ano_compra'                    => $data->anoCompra ?? null,
            'sequencial_compra'             => $this->resolveSequencialCompra($data),
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
            // Fase 1 não busca documentos. A coluna é deixada vazia para que a
            // Fase 2 (SyncDocumentosJob) saiba quais registros processar
            // (documentos_sincronizados_em IS NULL).
            'documentos'                    => json_encode([]),
        ];
    }

    /**
     * O PNCP manda `sequencialCompra` na raiz; em alguns itens só aparece
     * dentro de `documentos[]` ou embutido em `numeroControlePNCP`
     * (`{cnpj}-1-{sequencial}/{ano}`). Sem este inteiro a Fase 2 não
     * consegue chamar /arquivos (`whereNotNull('sequencial_compra')`).
     */
    private function resolveSequencialCompra(object $data): ?int
    {
        $candidates = [
            $data->sequencialCompra ?? null,
            $data->sequencial_compra ?? null,
        ];

        $documentos = $data->documentos ?? null;

        if (is_array($documentos) && $documentos !== []) {
            $primeiro = $documentos[0];
            $candidates[] = is_object($primeiro)
                ? ($primeiro->sequencialCompra ?? $primeiro->sequencial_compra ?? null)
                : (is_array($primeiro) ? ($primeiro['sequencialCompra'] ?? $primeiro['sequencial_compra'] ?? null) : null);
        }

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            return (int) $candidate;
        }

        $numeroControle = (string) ($data->numeroControlePNCP ?? '');

        if ($numeroControle !== '' && preg_match('/-(\d+)\/\d{4}$/', $numeroControle, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
