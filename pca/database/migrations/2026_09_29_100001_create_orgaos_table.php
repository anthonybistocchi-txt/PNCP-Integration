<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allowlist de órgãos monitorados (fonte do sync PCA).
     *
     * razao_social / poder / esfera vêm do import a partir de
     * contratacoes.payload_completo->orgaoEntidade (sem HTTP extra).
     * Valores são letras (não IDs numéricos): poder E|L|J|M, esfera F|E|M.
     */
    public function up(): void
    {
        Schema::create('orgaos', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj', 14)->unique();
            $table->string('razao_social', 255)->nullable();
            $table->char('poder', 1)->nullable();
            $table->char('esfera', 1)->nullable();
            $table->boolean('ativo')->default(true)->index();
            $table->string('importado_de', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orgaos');
    }
};
