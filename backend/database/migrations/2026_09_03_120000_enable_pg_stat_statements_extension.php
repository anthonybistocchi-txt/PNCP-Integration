<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * pg_stat_statements já está carregado via shared_preload_libraries
     * (.docker/postgres/postgresql.conf), mas isso só coloca o módulo em
     * memória. É o CREATE EXTENSION que cria a view pg_stat_statements e as
     * funções de reset — sem ele, a query abaixo não aparece em lugar nenhum:
     *
     *   SELECT query, calls, mean_exec_time, rows
     *   FROM pg_stat_statements
     *   ORDER BY mean_exec_time DESC
     *   LIMIT 20;
     *
     * auto_explain não precisa de extensão: só expõe parâmetros de sessão.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS pg_stat_statements');
    }
};
