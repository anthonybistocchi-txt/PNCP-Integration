<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_pca', function (Blueprint $table)
        {
            $table->id();
            $table->string('cnpj_orgao', 14);
            $table->smallInteger('ano_pca')->index();
            $table->integer('sequencial_pca');
            $table->string('codigo_unidade', 20)->nullable();
            $table->string('nome_unidade', 255)->nullable();
            $table->string('situacao_pca', 50)->nullable();
            $table->date('data_publicacao_pncp')->nullable();
            $table->dateTime('data_inclusao')->nullable();
            $table->dateTime('data_atualizacao')->nullable();
            $table->jsonb('payload_completo')->nullable();
            $table->timestamps();

            $table->unique(['cnpj_orgao', 'ano_pca', 'sequencial_pca']);
            $table->index(['cnpj_orgao', 'ano_pca']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos_pca');
    }
};
