<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * poderId, esferaId, modoDisputaId, tipoInstrumentoConvocatorioCodigo e
     * fontesOrcamentarias só existem dentro do JSONB payload_completo.
     * Sem índice, um whereIn(...) nesses campos força Seq Scan na tabela inteira.
     * Índices de expressão dão a mesma velocidade de uma coluna normal,
     * sem precisar duplicar o dado para fora do JSON nem rodar backfill.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_pc_poder_id
            ON public_contratations ((payload_completo->'orgaoEntidade'->>'poderId'))
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_pc_esfera_id
            ON public_contratations ((payload_completo->'orgaoEntidade'->>'esferaId'))
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_pc_modo_disputa_id
            ON public_contratations (((payload_completo->>'modoDisputaId')::int))
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_pc_tipo_instrumento_codigo
            ON public_contratations (((payload_completo->>'tipoInstrumentoConvocatorioCodigo')::int))
        ");

        // GIN com jsonb_path_ops: mais rápido e menor para consultas de contenção (@>),
        // que é exatamente o que whereJsonContains() gera.
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_pc_fontes_orcamentarias
            ON public_contratations USING gin ((payload_completo->'fontesOrcamentarias') jsonb_path_ops)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_pc_poder_id');
        DB::statement('DROP INDEX IF EXISTS idx_pc_esfera_id');
        DB::statement('DROP INDEX IF EXISTS idx_pc_modo_disputa_id');
        DB::statement('DROP INDEX IF EXISTS idx_pc_tipo_instrumento_codigo');
        DB::statement('DROP INDEX IF EXISTS idx_pc_fontes_orcamentarias');
    }
};
