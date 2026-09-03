<?php

namespace App\Actions\PublicContratations;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use App\DTOs\PublicContratations\PersonalizedContratationFiltersDTO;
use App\Enums\PublicContratations\KeywordMatchMode;
use App\Models\UserKeywords\UserKeyword;
use App\Services\PublicContratations\PublicContratationsService;
use App\Services\UserContratationFilters\UserContratationFilterService;
use App\Services\UserKeywords\UserKeywordService;

class ListRelevantContratationsAction
{
    public function __construct(
        private PublicContratationsService $publicContratationsService,
        private UserContratationFilterService $filterService,
        private UserKeywordService $keywordService
    ) {}

    public function listByUserId(
        int $userId,
        ListRelevantContratationsDTO $dto,
        KeywordMatchMode $mode = KeywordMatchMode::Both
    ): PaginatedResultDTO {
        $filtersGroupedByType = $this->filterService->listGroupedByType($userId);
        $keywordsGroupedByType = $this->keywordService->listGroupedByType($userId);
        [$includeKeywords, $excludeKeywords] = $this->keywordsForMode($keywordsGroupedByType, $mode);

        if ($this->missingRequiredKeywords($mode, $includeKeywords, $excludeKeywords)) {
            return $this->emptyResult();
        }

        $filters = new PersonalizedContratationFiltersDTO(
            uf:                                 $filtersGroupedByType['uf'] ?? [],
            codigoModalidadeContratacao:        $filtersGroupedByType['codigoModalidadeContratacao'] ?? [],
            poderId:                            $filtersGroupedByType['poderId'] ?? [],
            esferaId:                           $filtersGroupedByType['esferaId'] ?? [],
            situacaoCompraId:                   $filtersGroupedByType['situacaoCompraId'] ?? [],
            modoDisputaId:                      $filtersGroupedByType['modoDisputaId'] ?? [],
            tipoInstrumentoConvocatorioCodigo:  $filtersGroupedByType['tipoInstrumentoConvocatorioCodigo'] ?? [],
            fontesOrcamentarias:                $filtersGroupedByType['fontesOrcamentarias'] ?? [],
            includeKeywords:                    $includeKeywords,
            excludeKeywords:                    $excludeKeywords,
        );

        return $this->publicContratationsService->getPersonalized($filters, $dto);
    }

    /**
     * Cada endpoint usa só as palavras do modo pedido. Assim o include
     * nunca "herda" exclusão, e o exclude nunca aplica inclusão.
     *
     * @param array{include: list<string>, exclude: list<string>} $keywordsGroupedByType
     * @return array{0: list<string>, 1: list<string>}
     */
    private function keywordsForMode(array $keywordsGroupedByType, KeywordMatchMode $mode): array
    {
        $includeKeywords = $keywordsGroupedByType[UserKeyword::TYPE_INCLUDE] ?? [];
        $excludeKeywords = $keywordsGroupedByType[UserKeyword::TYPE_EXCLUDE] ?? [];

        return match ($mode) {
            KeywordMatchMode::Include => [$includeKeywords, []],
            KeywordMatchMode::Exclude => [[], $excludeKeywords],
            KeywordMatchMode::Both    => [$includeKeywords, $excludeKeywords],
        };
    }

    /**
     * Sem as palavras do modo, a query cairia só no filtro estrutural
     * (ou em nada) e devolveria a base inteira — o bug que estes
     * endpoints existem para evitar.
     *
     * @param list<string> $includeKeywords
     * @param list<string> $excludeKeywords
     */
    private function missingRequiredKeywords(
        KeywordMatchMode $mode,
        array $includeKeywords,
        array $excludeKeywords
    ): bool {
        return match ($mode) {
            KeywordMatchMode::Include => $includeKeywords === [],
            KeywordMatchMode::Exclude => $excludeKeywords === [],
            KeywordMatchMode::Both    => $includeKeywords === [] || $excludeKeywords === [],
        };
    }

    private function emptyResult(): PaginatedResultDTO
    {
        return new PaginatedResultDTO(
            data:        collect([]),
            page:        1,
            count_page:  0,
            count:       0,
            next_cursor: null,
            has_more:    false,
        );
    }
}
