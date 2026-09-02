<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adiciona a coluna "type" para diferenciar palavras-chave que devem
     * INCLUIR resultados ('include', comportamento atual e padrão) das que
     * devem EXCLUIR resultados ('exclude'). A unique constraint é refeita
     * para permitir a mesma palavra em tipos diferentes para o mesmo usuário.
     */
    public function up(): void
    {
        Schema::table('user_keywords', function (Blueprint $table) {
            $table->string('type', 10)->default('include')->after('keyword');
        });

        Schema::table('user_keywords', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'keyword']);
            $table->unique(['user_id', 'keyword', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_keywords', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'keyword', 'type']);
            $table->unique(['user_id', 'keyword']);
            $table->dropColumn('type');
        });
    }
};
