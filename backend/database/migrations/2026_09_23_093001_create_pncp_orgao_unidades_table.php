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
        Schema::create('pncp_orgao_unidades', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj_orgao', 18)->index();
            $table->string('codigo_unidade', 20);
            $table->string('nome_unidade', 255)->nullable();
            $table->char('uf_sigla', 2)->nullable()->index();
            $table->integer('codigo_ibge')->nullable();
            $table->string('nome_municipio', 100)->nullable();
            $table->char('poder_id', 1)->nullable();
            $table->char('esfera_id', 1)->nullable();
            $table->boolean('ativo')->default(true);
            $table->jsonb('payload_completo')->nullable();
            $table->timestamps();

            $table->unique(['cnpj_orgao', 'codigo_unidade']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pncp_orgao_unidades');
    }
};
