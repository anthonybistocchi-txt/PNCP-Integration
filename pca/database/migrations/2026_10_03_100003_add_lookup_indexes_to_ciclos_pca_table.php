<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tabela só tinha índice em sync_id. O pncp:sync-pca-faltantes e o
     * SyncPcaDataJob consultam por plano (cnpj, ano, sequencial) e por
     * status/started_at; sem índice isso degrada com a tabela crescendo
     * (~10 mil linhas por rodada completa).
     */
    public function up(): void
    {
        Schema::table('ciclos_pca', function (Blueprint $table)
        {
            $table->index(['cnpj_orgao', 'ano_pca', 'sequencial_pca', 'started_at'], 'ciclos_pca_plano_started_idx');
            $table->index(['status', 'started_at'], 'ciclos_pca_status_started_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ciclos_pca', function (Blueprint $table)
        {
            $table->dropIndex('ciclos_pca_plano_started_idx');
            $table->dropIndex('ciclos_pca_status_started_idx');
        });
    }
};
