<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indice parcial para a query de drenagem de documentos pendentes.
 *
 * SyncPncpPendingDocumentsJob::handle roda 720x/dia e sempre filtra:
 *   WHERE documents_fetched_at IS NULL AND codigo_modalidade_contratacao = ?
 *   ORDER BY data_publicacao_pncp DESC
 *
 * Sem indice parcial, o custo cresce proporcionalmente ao passivo total.
 * Com o indice parcial, apenas os registros com documents_fetched_at IS NULL
 * entram no indice, mantendo-o compacto mesmo com o tabela crescendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS public_contratations_pending_docs_idx
                ON public_contratations (codigo_modalidade_contratacao, data_publicacao_pncp DESC)
                WHERE documents_fetched_at IS NULL
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS public_contratations_pending_docs_idx');
    }
};
