<?php

namespace App\Actions\PublicContratations;

use App\Models\PublicContratations\PublicContratation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncPublicContratationDocumentsAction
{
    /**
     * Recebe um lote de documentos já buscados e persiste apenas as colunas
     * `documentos` e `documents_fetched_at`, sem tocar nos demais campos da
     * licitação. Isso garante que a Fase 1 (upsert principal) e a Fase 2
     * (documentos) possam rodar de forma totalmente independente.
     *
     * @param array<array{
     *     numero_controle_pncp: string,
     *     documentos: string,
     *     documents_fetched_at: string
     * }> $items
     */
    public function handle(array $items): void
    {
        if (empty($items)) {
            return;
        }

        try {
            DB::transaction(function () use ($items) {
                foreach ($items as $item) {
                    PublicContratation::where('numero_controle_pncp', $item['numero_controle_pncp'])
                        ->update([
                            'documentos'           => $item['documentos'],
                            'documents_fetched_at' => $item['documents_fetched_at'],
                        ]);
                }
            });

            Log::info('PNCP Documents Sync: Bulk Upserted ' . count($items) . ' document records.');
        } catch (\Throwable $error) {
            Log::error('PNCP_DOCUMENTS_UPSERT_DB_ERROR', [
                'reason'     => 'Falha ao executar o Bulk Upsert de documentos no banco de dados.',
                'message'    => $error->getMessage(),
                'batch_size' => count($items),
                'sample_key' => $items[0]['numero_controle_pncp'] ?? 'N/A',
            ]);

            throw $error;
        }
    }
}
