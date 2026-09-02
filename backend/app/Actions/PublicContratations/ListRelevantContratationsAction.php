<?php

namespace App\Actions\PublicContratations;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use App\DTOs\PublicContratations\PersonalizedContratationFiltersDTO;
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

    public function listByUserId(int $userId, ListRelevantContratationsDTO $dto): PaginatedResultDTO
    {
        $filtersGroupedByType = $this->filterService->listGroupedByType($userId);
        $keywordsGroupedByType = $this->keywordService->listGroupedByType($userId);

        $filters = new PersonalizedContratationFiltersDTO(
            uf:                                 $filtersGroupedByType['uf'] ?? [],
            codigoModalidadeContratacao:        $filtersGroupedByType['codigoModalidadeContratacao'] ?? [],
            poderId:                            $filtersGroupedByType['poderId'] ?? [],
            esferaId:                           $filtersGroupedByType['esferaId'] ?? [],
            situacaoCompraId:                   $filtersGroupedByType['situacaoCompraId'] ?? [],
            modoDisputaId:                      $filtersGroupedByType['modoDisputaId'] ?? [],
            tipoInstrumentoConvocatorioCodigo:  $filtersGroupedByType['tipoInstrumentoConvocatorioCodigo'] ?? [],
            fontesOrcamentarias:                $filtersGroupedByType['fontesOrcamentarias'] ?? [],
            includeKeywords:                    $keywordsGroupedByType[UserKeyword::TYPE_INCLUDE],
            excludeKeywords:                    $keywordsGroupedByType[UserKeyword::TYPE_EXCLUDE],
        );

        return $this->publicContratationsService->getPersonalized($filters, $dto);
    }
}
