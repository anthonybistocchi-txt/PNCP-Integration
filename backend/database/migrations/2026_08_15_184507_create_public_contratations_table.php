<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('public_contratations', function (Blueprint $table) {
            // numero_controle_pncp será a chave primária
            $table->string('numero_controle_pncp')->primary();

            // Colunas estratégicas para buscas locais
            $table->string('orgao_cnpj')->index();
            $table->integer('ano_compra')->index();
            $table->dateTime('data_publicacao_pncp')->index();
            $table->dateTime('data_atualizacao_global');
            $table->string('uf', 2)->nullable()->index();
            $table->integer('codigo_municipio_ibge')->nullable()->index();
            $table->integer('codigo_modalidade_contratacao')->nullable()->index();

            // --- Novas colunas estratégicas adicionadas ---
            $table->integer('situacao_compra_id')->nullable()->index();
            $table->bigInteger('valor_total_estimado')->nullable()->index();
            $table->dateTime('data_abertura_proposta')->nullable()->index();
            $table->dateTime('data_encerramento_proposta')->nullable()->index();
            $table->boolean('srp')->default(false)->index();
            $table->text('objeto_compra')->nullable();

            // Aqui guardamos todo o JSON da requisição para podermos recuperar
            // e processar no Resource sem ter que criar 50 colunas.
            // Usamos jsonb que no Postgres é ultra-rápido e automaticamente comprimido
            $table->jsonb('payload_completo');

            // Lista de arquivos/documentos recuperados do PNCP
            $table->jsonb('documentos')->nullable();

            $table->timestamps();
        });

        // Adiciona o index de Full Text Search para o objeto_compra (em Português)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX public_contratations_objeto_compra_fts ON public_contratations USING gin(to_tsvector('portuguese', objeto_compra))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS public_contratations_objeto_compra_fts");
        Schema::dropIfExists('public_contratations');
    }
};
