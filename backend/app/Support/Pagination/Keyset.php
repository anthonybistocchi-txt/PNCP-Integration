<?php

namespace App\Support\Pagination;

/**
 * Descritor imutável de uma ordenação keyset.
 *
 * Centraliza todos os parâmetros necessários para gerar os predicados
 * "before" e "after" do cursor, o encode/decode e a ordenação final —
 * eliminando a duplicação entre PcaItemRepository e PublicContratationRepository.
 *
 * Instâncias canônicas: Keyset::pca(), Keyset::publicContratations() e Keyset::orgao().
 */
readonly class Keyset
{
    public function __construct(
        /** Coluna SQL qualificada usada na ordenação principal (ex: `pca_items.data_publicacao_pncp`). */
        public string $primaryColumn,
        /** Atributo do model para ler o valor (ex: `data_publicacao_pncp`). */
        public string $primaryAttribute,
        /** Chave no JSON do cursor (ex: `date`). */
        public string $primaryKey,
        /** Direção da ordenação principal: `'asc'` ou `'desc'`. */
        public string $direction,
        /** Coluna SQL de desempate — nullable quando não há desempate. */
        public ?string $tieColumn,
        /** Atributo do model de desempate — nullable. */
        public ?string $tieAttribute,
        /** Chave no JSON do cursor para o campo de desempate — nullable. */
        public ?string $tieKey,
        /** Tipo do valor de desempate para cast no decode: `'int'` ou `'string'`. */
        public string $tieType = 'string',
        /** Formato de data para encode do valor primário (ex: `'Y-m-d'` ou `'Y-m-d H:i:s'`). */
        public ?string $primaryFormat = null,
        /** Se true, aplica Carbon::parse → timezone do app → format no decode do cursor. */
        public bool $normalizeDate = false,
    ) {}

    /**
     * Keyset para pca_items:
     *   ORDER BY pca_items.data_publicacao_pncp DESC, pca_items.id ASC
     */
    public static function pca(): self
    {
        return new self(
            primaryColumn: 'pca_items.data_publicacao_pncp',
            primaryAttribute: 'data_publicacao_pncp',
            primaryKey: 'date',
            direction: 'desc',
            tieColumn: 'pca_items.id',
            tieAttribute: 'id',
            tieKey: 'id',
            tieType: 'int',
            primaryFormat: 'Y-m-d',
            normalizeDate: false,
        );
    }

    /**
     * Keyset para public_contratations:
     *   ORDER BY data_publicacao_pncp DESC, numero_controle_pncp ASC
     */
    public static function publicContratations(): self
    {
        return new self(
            primaryColumn: 'data_publicacao_pncp',
            primaryAttribute: 'data_publicacao_pncp',
            primaryKey: 'date',
            direction: 'desc',
            tieColumn: 'numero_controle_pncp',
            tieAttribute: 'numero_controle_pncp',
            tieKey: 'numero_controle',
            tieType: 'string',
            primaryFormat: 'Y-m-d H:i:s',
            normalizeDate: true,
        );
    }

    /**
     * Keyset para pncp_orgaos:
     *   ORDER BY cnpj ASC, id ASC
     */
    public static function orgao(): self
    {
        return new self(
            primaryColumn: 'cnpj',
            primaryAttribute: 'cnpj',
            primaryKey: 'cnpj',
            direction: 'asc',
            tieColumn: 'id',
            tieAttribute: 'id',
            tieKey: 'id',
            tieType: 'int',
        );
    }
}
