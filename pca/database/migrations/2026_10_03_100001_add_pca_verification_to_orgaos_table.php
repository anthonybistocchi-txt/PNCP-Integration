<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Memória de "este órgão não tem PCA no ano".
     *
     * Em 03/10 só 1.936 dos 7.346 órgãos ativos tinham algum PCA; os outros
     * 5.410 gastavam uma chamada `consolidado/unidades` por rodada para
     * receber 404. `pca_tem_plano = false` + `pca_ano_verificado` +
     * `pca_verificado_em` deixam o SyncPcaCommand pular esses órgãos até
     * `pncp.pca.recheck_empty_days`.
     *
     * Nulo = nunca verificado (será consultado na próxima rodada).
     */
    public function up(): void
    {
        Schema::table('orgaos', function (Blueprint $table)
        {
            $table->smallInteger('pca_ano_verificado')->nullable();
            $table->dateTime('pca_verificado_em')->nullable();
            $table->boolean('pca_tem_plano')->nullable();

            $table->index(['pca_tem_plano', 'pca_ano_verificado'], 'orgaos_pca_verificacao_idx');
        });

        // Quem já tem plano no ano corrente está verificado como "com PCA".
        // Os demais ficam nulos de propósito: não dá para distinguir "vazio"
        // de "o job morreu na fila", então a primeira rodada os consulta uma vez.
        if (DB::getDriverName() === 'pgsql')
        {
            DB::statement(<<<'SQL'
                UPDATE orgaos
                SET pca_tem_plano = TRUE,
                    pca_ano_verificado = EXTRACT(YEAR FROM now())::smallint,
                    pca_verificado_em = now()
                WHERE EXISTS (
                    SELECT 1 FROM planos_pca p
                    WHERE p.cnpj_orgao = orgaos.cnpj
                      AND p.ano_pca = EXTRACT(YEAR FROM now())::smallint
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('orgaos', function (Blueprint $table)
        {
            $table->dropIndex('orgaos_pca_verificacao_idx');
            $table->dropColumn(['pca_ano_verificado', 'pca_verificado_em', 'pca_tem_plano']);
        });
    }
};
