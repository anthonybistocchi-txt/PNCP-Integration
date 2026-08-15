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
        Schema::create('public_contratations', function (Blueprint $table) {
            // numero_controle_pncp será a chave primária
            $table->string('numero_controle_pncp')->primary();
            
            // Colunas estratégicas para buscas locais
            $table->string('orgao_cnpj')->index();
            $table->integer('ano_compra')->index();
            $table->dateTime('data_publicacao_pncp')->index();
            $table->dateTime('data_atualizacao_global');
            $table->string('uf', 2)->nullable()->index();
            $table->string('codigo_municipio_ibge', 7)->nullable()->index();
            $table->string('codigo_modalidade_contratacao')->nullable()->index();
            
            // Aqui guardamos todo o JSON da requisição para podermos recuperar
            // e processar no Resource sem ter que criar 50 colunas.
            $table->json('payload_completo');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('public_contratations');
    }
};
