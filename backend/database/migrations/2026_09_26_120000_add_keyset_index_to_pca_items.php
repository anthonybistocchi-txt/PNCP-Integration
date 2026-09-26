<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indice composto para a ordenacao keyset do cursor de pca_items.
 *
 * Sem esse indice, a query da API de PCA ordena em memoria a cada request.
 * O indice atende diretamente:
 *   ORDER BY pca_items.data_publicacao_pncp DESC, pca_items.id ASC
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS pca_items_data_publicacao_id_idx
                ON pca_items (data_publicacao_pncp DESC, id ASC)
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS pca_items_data_publicacao_id_idx');
    }
};
