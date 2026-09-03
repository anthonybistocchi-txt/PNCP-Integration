<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * Aplica o filtro de full-text do objeto_compra.
 *
 * A expressão precisa ser idêntica ao índice GIN
 * `to_tsvector('portuguese', objeto_compra)` — sem COALESCE — para o
 * planejador usar o índice. Inclusão e exclusão são cláusulas AND
 * independentes: A, NOT B, ou A AND NOT B. Nunca De Morgan.
 */
final class ContratationKeywordFilter
{
    /**
     * @param list<string> $includeKeywords
     * @param list<string> $excludeKeywords
     */
    public function apply(Builder $query, array $includeKeywords, array $excludeKeywords): Builder
    {
        if ($includeKeywords !== []) {
            $query->whereRaw(
                $this->expression($includeKeywords),
                $includeKeywords
            );
        }

        if ($excludeKeywords !== []) {
            $query->whereRaw(
                'NOT (' . $this->expression($excludeKeywords) . ')',
                $excludeKeywords
            );
        }

        return $query;
    }

    /**
     * @param list<string> $keywords
     */
    private function expression(array $keywords): string
    {
        $condicoesPorFrase = implode(' || ', array_fill(
            0,
            count($keywords),
            "phraseto_tsquery('portuguese', ?)"
        ));

        return "to_tsvector('portuguese', objeto_compra) @@ ({$condicoesPorFrase})";
    }
}
