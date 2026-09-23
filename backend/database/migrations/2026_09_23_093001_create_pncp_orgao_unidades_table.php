<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unidades com PCA no ano, via GET .../pca/{ano}/consolidado/unidades.
     *
     * Payload da API traz: codigoUnidade, nomeUnidade, poder, esfera,
     * sequencialPca, quantidade, valorTotal, razaoSocial — NÃO traz
     * uf/IBGE/município. Esses três campos são enriquecidos a partir de
     * public_contratations.payload_completo->unidadeOrgao (mesmo cnpj +
     * codigoUnidade) e portanto permanecem nullable.
     *
     * `poder`/`esfera` são letras (E|L|J|M e F|E|M), não IDs numéricos.
     */
    public function up(): void
    {
        Schema::create('pncp_orgao_unidades', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj_orgao', 14);
            $table->smallInteger('ano_pca');
            $table->string('codigo_unidade', 32);
            $table->string('nome_unidade', 255)->nullable();

            // Enriquecidos de public_contratations (API de unidades não envia).
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
                ->on('pncp_orgaos')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pncp_orgao_unidades');
    }
};
