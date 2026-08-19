<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adiciona índices estratégicos para acelerar as queries de leitura mais comuns.
     *
     * 1. Índice composto (uf, codigo_modalidade_contratacao, data_publicacao_pncp):
     *    Elimina Seq Scans nos filtros mais comuns da API de leitura.
     *    É um índice PARCIAL (WHERE situacao_compra_id = 1), ou seja, o PostgreSQL
     *    só indexa os registros ativos, reduzindo drasticamente o tamanho do índice
     *    e acelerando INSERT/UPDATE no ciclo de sincronização.
     *
     * 2. Índice em data_atualizacao_global:
     *    Necessário para o filtro tipoData = 'atualizacao' no Repository.
     *    Sem esse índice, cada query desse tipo faz Seq Scan na tabela inteira.
     *
     * 3. Índice em (data_publicacao_pncp DESC, numero_controle_pncp ASC):
     *    Suporta a Keyset Pagination (cursor-based) implementada no Repository.
     */
    public function up(): void
    {
        // Índice composto para o filtro principal (uf + modalidade + data de publicação)
        // Índice parcial: cobre apenas registros ativos, tornando-o menor e mais rápido
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_contratations_filter_composite
            ON public_contratations (uf, codigo_modalidade_contratacao, data_publicacao_pncp DESC)
            WHERE situacao_compra_id = 1
        ');

        // Índice para o filtro de data de atualização (tipoData = "atualizacao")
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_contratations_data_atualizacao
            ON public_contratations (data_atualizacao_global DESC)
        ');

        // Índice composto para suportar a Keyset Pagination com ordenação estável
        // (data_publicacao_pncp DESC, numero_controle_pncp ASC) garante páginas sem repetição
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_contratations_keyset_cursor
            ON public_contratations (data_publicacao_pncp DESC, numero_controle_pncp ASC)
        ');
    }

    /**
     * Remove os índices adicionados.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_contratations_filter_composite');
        DB::statement('DROP INDEX IF EXISTS idx_contratations_data_atualizacao');
        DB::statement('DROP INDEX IF EXISTS idx_contratations_keyset_cursor');
    }
};
