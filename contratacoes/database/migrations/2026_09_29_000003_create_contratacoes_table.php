<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratacoes', function (Blueprint $table) {
            $table->string('numero_controle_pncp')->primary();

            $table->string('orgao_cnpj')->index();
            $table->integer('ano_compra')->index();
            $table->integer('sequencial_compra')->nullable();
            $table->dateTime('data_publicacao_pncp')->index();
            $table->dateTime('data_atualizacao_global');
            $table->string('uf', 2)->nullable()->index();
            $table->integer('codigo_municipio_ibge')->nullable()->index();
            $table->integer('codigo_modalidade_contratacao')->nullable()->index();

            $table->integer('situacao_compra_id')->nullable()->index();
            $table->bigInteger('valor_total_estimado')->nullable()->index();
            $table->dateTime('data_abertura_proposta')->nullable()->index();
            $table->dateTime('data_encerramento_proposta')->nullable()->index();
            $table->boolean('srp')->default(false)->index();
            $table->text('objeto_compra')->nullable();

            $table->jsonb('payload_completo');
            $table->jsonb('documentos')->nullable();
            $table->timestamp('documentos_sincronizados_em')->nullable();

            $table->timestamps();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE INDEX contratacoes_objeto_compra_fts ON contratacoes USING gin(to_tsvector('portuguese', objeto_compra))");

        DB::statement('
            CREATE INDEX idx_contratacoes_filter_composite
            ON contratacoes (uf, codigo_modalidade_contratacao, data_publicacao_pncp DESC)
            WHERE situacao_compra_id = 1
        ');

        DB::statement('
            CREATE INDEX idx_contratacoes_data_atualizacao
            ON contratacoes (data_atualizacao_global DESC)
        ');

        DB::statement('
            CREATE INDEX idx_contratacoes_keyset_cursor
            ON contratacoes (data_publicacao_pncp DESC, numero_controle_pncp ASC)
        ');

        DB::statement("
            CREATE INDEX idx_contratacoes_poder_id
            ON contratacoes ((payload_completo->'orgaoEntidade'->>'poderId'))
        ");

        DB::statement("
            CREATE INDEX idx_contratacoes_esfera_id
            ON contratacoes ((payload_completo->'orgaoEntidade'->>'esferaId'))
        ");

        DB::statement("
            CREATE INDEX idx_contratacoes_modo_disputa_id
            ON contratacoes (((payload_completo->>'modoDisputaId')::int))
        ");

        DB::statement("
            CREATE INDEX idx_contratacoes_tipo_instrumento_codigo
            ON contratacoes (((payload_completo->>'tipoInstrumentoConvocatorioCodigo')::int))
        ");

        DB::statement("
            CREATE INDEX idx_contratacoes_fontes_orcamentarias
            ON contratacoes USING gin ((payload_completo->'fontesOrcamentarias') jsonb_path_ops)
        ");

        DB::statement(<<<'SQL'
            CREATE INDEX contratacoes_pending_docs_idx
                ON contratacoes (codigo_modalidade_contratacao, data_publicacao_pncp DESC)
                WHERE documentos_sincronizados_em IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contratacoes');
    }
};
