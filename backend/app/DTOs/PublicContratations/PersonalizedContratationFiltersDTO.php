<?php

namespace App\DTOs\PublicContratations;

/**
 * Agrupa as preferências salvas de um cliente (filtros de dimensão +
 * palavras-chave de inclusão/exclusão) para montar a busca personalizada
 * de contratações em uma única query.
 *
 * Uma dimensão vazia significa "sem restrição" (não filtra por ela).
 */
final class PersonalizedContratationFiltersDTO
{
    /**
     * @param list<string> $uf
     * @param list<string> $codigoModalidadeContratacao
     * @param list<string> $poderId
     * @param list<string> $esferaId
     * @param list<string> $situacaoCompraId
     * @param list<string> $modoDisputaId
     * @param list<string> $tipoInstrumentoConvocatorioCodigo
     * @param list<string> $fontesOrcamentarias
     * @param list<string> $includeKeywords
     * @param list<string> $excludeKeywords
     */
    public function __construct(
        public readonly array $uf = [],
        public readonly array $codigoModalidadeContratacao = [],
        public readonly array $poderId = [],
        public readonly array $esferaId = [],
        public readonly array $situacaoCompraId = [],
        public readonly array $modoDisputaId = [],
        public readonly array $tipoInstrumentoConvocatorioCodigo = [],
        public readonly array $fontesOrcamentarias = [],
        public readonly array $includeKeywords = [],
        public readonly array $excludeKeywords = [],
    ) {}

    /**
     * Verdadeiro quando o cliente não configurou nenhuma dimensão de filtro
     * nem nenhuma palavra-chave de inclusão. Usado como guarda para não
     * devolver a base inteira de contratações quando nada foi configurado.
     */
    public function hasNoActiveFilters(): bool
    {
        return empty($this->uf)
            && empty($this->codigoModalidadeContratacao)
            && empty($this->poderId)
            && empty($this->esferaId)
            && empty($this->situacaoCompraId)
            && empty($this->modoDisputaId)
            && empty($this->tipoInstrumentoConvocatorioCodigo)
            && empty($this->fontesOrcamentarias)
            && empty($this->includeKeywords);
    }
}
