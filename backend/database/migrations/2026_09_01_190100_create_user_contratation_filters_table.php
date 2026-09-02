<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabela genérica de filtros por cliente: uma linha por valor selecionado.
     * filter_type usa os mesmos nomes de campo do DTO de busca
     * (uf, codigoModalidadeContratacao, poderId, esferaId, situacaoCompraId,
     * modoDisputaId, tipoInstrumentoConvocatorioCodigo, fontesOrcamentarias).
     * Um usuário sem nenhuma linha para um filter_type não é restringido nessa dimensão.
     */
    public function up(): void
    {
        Schema::create('user_contratation_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('filter_type', 40);
            $table->string('value', 100);
            $table->timestamps();

            // Evita duplicar o mesmo valor para o mesmo filtro do mesmo usuário
            $table->unique(['user_id', 'filter_type', 'value']);
            $table->index(['user_id', 'filter_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_contratation_filters');
    }
};
