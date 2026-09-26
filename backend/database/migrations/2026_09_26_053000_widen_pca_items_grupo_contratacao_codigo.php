<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O PNCP passou a enviar `grupoContratacaoCodigo` como UUID (36 chars),
     * e não mais como o código legível curto ("120628-3/2026"). A coluna
     * varchar(20) estoura com "value too long for type character varying(20)".
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pca_items ALTER COLUMN grupo_contratacao_codigo TYPE varchar(255)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pca_items ALTER COLUMN grupo_contratacao_codigo TYPE varchar(20)');
        }
    }
};
