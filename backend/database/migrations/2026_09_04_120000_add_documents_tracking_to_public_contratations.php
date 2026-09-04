<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('public_contratations', function (Blueprint $table) {
            // Identificador sequencial da compra no PNCP.
            // Junto com orgao_cnpj e ano_compra, forma a chave para o endpoint
            // /api/pncp/v1/orgaos/{cnpj}/compras/{ano}/{sequencial}/arquivos.
            // Coluna dedicada evita extração de JSONB e permite indexação futura.
            $table->integer('sequencial_compra')->nullable()->after('ano_compra');

            // Marca o momento em que os documentos (editais, anexos) foram
            // buscados pela Fase 2 (SyncPncpDocumentsJob).
            // NULL = ainda não sincronizado.
            // Preenchido = última sincronização bem-sucedida.
            $table->timestamp('documents_fetched_at')->nullable()->after('documentos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('public_contratations', function (Blueprint $table) {
            $table->dropColumn(['sequencial_compra', 'documents_fetched_at']);
        });
    }
};
