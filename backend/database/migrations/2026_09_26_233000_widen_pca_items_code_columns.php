<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O PNCP envia códigos em notação de pontos (ex.: "3.3.90.30.09.00.001.20666")
     * em colunas de "código" que estavam como varchar(20) e estouram com
     * "value too long for type character varying(20)".
     *
     * `grupo_contratacao_codigo` já foi alargado em migration própria; aqui
     * alargamos os demais códigos que recebem dados externos do PNCP.
     */
    private const COLUMNS = [
        'classificacao_superior_codigo',
        'pdm_codigo',
        'codigo_item',
        'unidade_fornecimento',
        'codigo_unidade_requisitante',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE pca_items ALTER COLUMN {$column} TYPE varchar(255)");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE pca_items ALTER COLUMN {$column} TYPE varchar(20)");
        }
    }
};
