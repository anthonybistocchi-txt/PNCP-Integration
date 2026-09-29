<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha por valor selecionado. filter_type usa os mesmos nomes de
     * campo do DTO de busca. Sem linha para um filter_type, essa dimensão
     * não restringe o usuário.
     */
    public function up(): void
    {
        Schema::create('filtros_contratacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('filter_type', 40);
            $table->string('value', 100);
            $table->timestamps();

            $table->unique(['user_id', 'filter_type', 'value']);
            $table->index(['user_id', 'filter_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filtros_contratacao');
    }
};
