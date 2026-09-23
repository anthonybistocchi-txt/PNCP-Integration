<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pca_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pca_plan_id')->constrained('pca_plans')->onDelete('cascade');
            $table->integer('numero_item');
            
            $table->smallInteger('categoria_item_pca')->nullable();
            $table->string('categoria_item_pca_nome', 100)->nullable();
            
            $table->smallInteger('catalogo_id')->nullable();
            $table->smallInteger('classificacao_catalogo_id')->nullable();
            
            $table->string('classificacao_superior_codigo', 20)->nullable();
            $table->string('classificacao_superior_nome', 255)->nullable();
            
            $table->string('pdm_codigo', 20)->nullable();
            $table->string('pdm_nome', 255)->nullable();
            
            $table->string('codigo_item', 20)->nullable();
            $table->text('descricao_item')->nullable();
            
            $table->string('unidade_fornecimento', 20)->nullable();
            
            $table->decimal('quantidade', 15, 4)->nullable();
            $table->decimal('valor_unitario', 15, 2)->nullable();
            $table->decimal('valor_total', 17, 2)->nullable();
            $table->decimal('valor_orcamento_exercicio', 17, 2)->nullable();
            
            $table->date('data_desejada')->nullable();
            
            $table->string('unidade_requisitante', 255)->nullable();
            $table->string('codigo_unidade_requisitante', 20)->nullable();
            
            $table->string('grupo_contratacao_codigo', 20)->nullable();
            $table->string('grupo_contratacao_nome', 255)->nullable();
            
            $table->date('data_publicacao_pncp')->nullable();
            $table->dateTime('data_inclusao')->nullable();
            $table->dateTime('data_atualizacao')->nullable();
            
            $table->timestamps();

            $table->unique(['pca_plan_id', 'numero_item']);
            $table->index(['pca_plan_id', 'categoria_item_pca']);
            $table->index(['pca_plan_id', 'data_desejada']);
            $table->index(['pca_plan_id', 'grupo_contratacao_codigo']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE pca_items
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
            DB::statement("CREATE INDEX pca_items_search_vector_gin ON pca_items USING GIN(search_vector)");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS pca_items_search_vector_gin");
        Schema::dropIfExists('pca_items');
    }
};
