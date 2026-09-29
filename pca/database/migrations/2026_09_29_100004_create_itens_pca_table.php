<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_pca', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_pca_id')->constrained('planos_pca')->onDelete('cascade');
            $table->integer('numero_item');

            $table->smallInteger('categoria_item_pca')->nullable();
            $table->string('categoria_item_pca_nome', 100)->nullable();

            $table->smallInteger('catalogo_id')->nullable();
            $table->smallInteger('classificacao_catalogo_id')->nullable();

            $table->string('classificacao_superior_codigo', 255)->nullable();
            $table->string('classificacao_superior_nome', 255)->nullable();

            $table->string('pdm_codigo', 255)->nullable();
            $table->string('pdm_nome', 255)->nullable();

            $table->string('codigo_item', 255)->nullable();
            $table->text('descricao_item')->nullable();

            $table->string('unidade_fornecimento', 255)->nullable();

            $table->decimal('quantidade', 15, 4)->nullable();
            $table->decimal('valor_unitario', 15, 2)->nullable();
            $table->decimal('valor_total', 17, 2)->nullable();
            $table->decimal('valor_orcamento_exercicio', 17, 2)->nullable();

            $table->date('data_desejada')->nullable();

            $table->string('unidade_requisitante', 255)->nullable();
            $table->string('codigo_unidade_requisitante', 255)->nullable();

            $table->string('grupo_contratacao_codigo', 255)->nullable();
            $table->string('grupo_contratacao_nome', 255)->nullable();

            $table->date('data_publicacao_pncp')->nullable();
            $table->dateTime('data_inclusao')->nullable();
            $table->dateTime('data_atualizacao')->nullable();

            $table->timestamps();

            $table->unique(['plano_pca_id', 'numero_item']);
            $table->index(['plano_pca_id', 'categoria_item_pca']);
            $table->index(['plano_pca_id', 'data_desejada']);
            $table->index(['plano_pca_id', 'grupo_contratacao_codigo']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            ALTER TABLE itens_pca
            ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                to_tsvector('portuguese',
                    coalesce(descricao_item, '') || ' ' ||
                    coalesce(classificacao_superior_nome, '') || ' ' ||
                    coalesce(grupo_contratacao_nome, '') || ' ' ||
                    coalesce(unidade_requisitante, '') || ' ' ||
                    coalesce(pdm_nome, '')
                )
            ) STORED
        ");
        DB::statement('CREATE INDEX itens_pca_search_vector_gin ON itens_pca USING GIN(search_vector)');
        DB::statement(<<<'SQL'
            CREATE INDEX itens_pca_data_publicacao_id_idx
                ON itens_pca (data_publicacao_pncp DESC, id ASC)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_pca');
    }
};
