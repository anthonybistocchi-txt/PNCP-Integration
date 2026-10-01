<?php

namespace App\Actions\Pca;

use App\Models\Pca\PcaPlan;
use Illuminate\Support\Facades\Log;

class SyncPcaPlanAction
{
    /**
     * @param \App\DTOs\Pca\PcaPlanData[] $plans
     */
    public function handle(array $plans): void
    {
        if (empty($plans))
        {
            return;
        }

        $upsertData = [];

        foreach ($plans as $plan)
        {
            $upsertData[] = $plan->toArray();
        }

        try
        {
            PcaPlan::upsert(
                $upsertData,
                ['cnpj_orgao', 'ano_pca', 'sequencial_pca'],
                [
                    'codigo_unidade',
                    'nome_unidade',
                    'situacao_pca',
                    'data_publicacao_pncp',
                    'data_inclusao',
                    'data_atualizacao',
                    'payload_completo',
                ]
            );
        }
        catch (\Throwable $error)
        {
            Log::error('PNCP_PCA_PLAN_UPSERT_ERROR', [
                'reason'     => 'Falha ao executar Bulk Upsert de PcaPlans',
                'message'    => $error->getMessage(),
                'batch_size' => count($upsertData),
            ]);

            throw $error;
        }
    }
}
