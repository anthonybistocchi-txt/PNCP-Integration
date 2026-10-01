<?php

namespace App\Actions\Pca;

use App\Models\Pca\PcaItem;
use Illuminate\Support\Facades\Log;

class SyncPcaItemsAction
{
    /**
     * @param \App\DTOs\Pca\PcaItemData[] $items
     */
    public function handle(array $items): void
    {
        if (empty($items))
        {
            return;
        }

        $upsertData = [];

        foreach ($items as $item)
        {
            $upsertData[] = $item->toArray();
        }

        try
        {
            PcaItem::upsert(
                $upsertData,
                ['plano_pca_id', 'numero_item'],
                [
                    'categoria_item_pca',
                    'categoria_item_pca_nome',
                    'catalogo_id',
                    'classificacao_catalogo_id',
                    'classificacao_superior_codigo',
                    'classificacao_superior_nome',
                    'pdm_codigo',
                    'pdm_nome',
                    'codigo_item',
                    'descricao_item',
                    'unidade_fornecimento',
                    'quantidade',
                    'valor_unitario',
                    'valor_total',
                    'valor_orcamento_exercicio',
                    'data_desejada',
                    'unidade_requisitante',
                    'codigo_unidade_requisitante',
                    'grupo_contratacao_codigo',
                    'grupo_contratacao_nome',
                    'data_publicacao_pncp',
                    'data_inclusao',
                    'data_atualizacao',
                ]
            );
        }
        catch (\Throwable $error)
        {
            Log::error('PNCP_PCA_ITEM_UPSERT_ERROR', [
                'reason'     => 'Falha ao executar Bulk Upsert de PcaItems',
                'message'    => $error->getMessage(),
                'batch_size' => count($upsertData),
            ]);

            throw $error;
        }
    }
}
