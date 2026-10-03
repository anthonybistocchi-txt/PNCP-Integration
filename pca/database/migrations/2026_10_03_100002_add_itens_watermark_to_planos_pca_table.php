<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca d'água dos itens de cada plano.
     *
     * O mestre grava o plano ANTES de baixar os itens, então `data_atualizacao`
     * em planos_pca não diz se os itens estão em dia: um plano cujas páginas
     * morreram já apareceria "atualizado". Estas colunas só são preenchidas
     * quando o ciclo do plano fecha completo (itens_processados >= anunciado).
     *
     * - itens_sincronizados_em: `started_at` do ciclo (início do download),
     *   para que uma atualização feita DURANTE o download conte como posterior.
     * - itens_total_sincronizado: quantos itens esse download gravou.
     */
    public function up(): void
    {
        Schema::table('planos_pca', function (Blueprint $table)
        {
            $table->dateTime('itens_sincronizados_em')->nullable();
            $table->integer('itens_total_sincronizado')->nullable();

            $table->index(['ano_pca', 'itens_sincronizados_em'], 'planos_pca_itens_sync_idx');
        });

        // Semente: plano cujo ciclo MAIS RECENTE fechou completo já tem os
        // itens em dia. Sem isso a primeira rodada rebaixaria ~1,4M de itens
        // corretos. Se o plano mudou depois, `data_atualizacao` (API) > marca
        // e ele volta a ser pendente sozinho.
        if (DB::getDriverName() === 'pgsql')
        {
            DB::statement(<<<'SQL'
                UPDATE planos_pca p
                SET itens_sincronizados_em = c.started_at,
                    itens_total_sincronizado = c.itens_processados
                FROM (
                    SELECT DISTINCT ON (cnpj_orgao, ano_pca, sequencial_pca)
                           cnpj_orgao, ano_pca, sequencial_pca,
                           status, started_at, itens_processados, total_anunciado
                    FROM ciclos_pca
                    WHERE sequencial_pca IS NOT NULL
                    ORDER BY cnpj_orgao, ano_pca, sequencial_pca,
                             started_at DESC NULLS LAST, id DESC
                ) c
                WHERE c.cnpj_orgao = p.cnpj_orgao
                  AND c.ano_pca = p.ano_pca
                  AND c.sequencial_pca = p.sequencial_pca
                  AND c.status = 'finished'
                  AND c.total_anunciado > 0
                  AND c.itens_processados >= c.total_anunciado
                  AND c.started_at IS NOT NULL
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('planos_pca', function (Blueprint $table)
        {
            $table->dropIndex('planos_pca_itens_sync_idx');
            $table->dropColumn(['itens_sincronizados_em', 'itens_total_sincronizado']);
        });
    }
};
