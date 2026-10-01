<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unidades com PCA no ano, via GET .../pca/{ano}/consolidado/unidades.
     *
     * A API não envia uf/IBGE/município. Esses campos são enriquecidos a
     * partir de contratacoes.payload_completo->unidadeOrgao e ficam nullable.
     * poder/esfera são letras (E|L|J|M e F|E|M).
     */
    public function up(): void
    {
        Schema::create('unidades_orgao', function (Blueprint $table)
        {
            $table->id();
            $table->string('cnpj_orgao', 14);
            $table->smallInteger('ano_pca');
            $table->string('codigo_unidade', 32);
            $table->string('nome_unidade', 255)->nullable();
            $table->char('uf_sigla', 2)->nullable()->index();
            $table->integer('codigo_ibge')->nullable()->index();
            $table->string('nome_municipio', 100)->nullable();
            $table->char('poder', 1)->nullable();
            $table->char('esfera', 1)->nullable();
            $table->boolean('ativo')->default(true);
            $table->jsonb('payload_completo')->nullable();
            $table->timestamps();

            $table->unique(['cnpj_orgao', 'ano_pca', 'codigo_unidade']);
            $table->index(['cnpj_orgao', 'ano_pca']);

            $table->foreign('cnpj_orgao')
                ->references('cnpj')
                ->on('orgaos')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_orgao');
    }
};
