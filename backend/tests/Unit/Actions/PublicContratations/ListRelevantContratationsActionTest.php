<?php

namespace Tests\Unit\Actions\PublicContratations;

use App\Actions\PublicContratations\ListRelevantContratationsAction;
use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use App\DTOs\PublicContratations\PersonalizedContratationFiltersDTO;
use App\Enums\PublicContratations\KeywordMatchMode;
use App\Models\UserKeywords\UserKeyword;
use App\Services\PublicContratations\PublicContratationsService;
use App\Services\UserContratationFilters\UserContratationFilterService;
use App\Services\UserKeywords\UserKeywordService;
use Mockery;
use Tests\TestCase;

class ListRelevantContratationsActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_include_envia_so_palavras_de_inclusao(): void
    {
        $result = $this->actionComPalavras(['limpeza'], ['carro'])
            ->listByUserId(2, new ListRelevantContratationsDTO(), KeywordMatchMode::Include);

        $this->assertSame(0, $result->count);
    }

    public function test_exclude_envia_so_palavras_de_exclusao(): void
    {
        $result = $this->actionComPalavras([], ['carro'], expectedInclude: [], expectedExclude: ['carro'])
            ->listByUserId(2, new ListRelevantContratationsDTO(), KeywordMatchMode::Exclude);

        $this->assertSame(0, $result->count);
    }

    public function test_both_envia_as_duas_listas(): void
    {
        $result = $this->actionComPalavras(['limpeza'], ['carro'], expectedInclude: ['limpeza'], expectedExclude: ['carro'])
            ->listByUserId(2, new ListRelevantContratationsDTO(), KeywordMatchMode::Both);

        $this->assertSame(0, $result->count);
    }

    public function test_include_sem_palavras_nao_consulta_o_banco(): void
    {
        $action = $this->actionQueNaoPodeConsultar(
            includeKeywords: [],
            excludeKeywords: ['carro'],
        );

        $result = $action->listByUserId(2, new ListRelevantContratationsDTO(), KeywordMatchMode::Include);

        $this->assertSame(0, $result->count);
        $this->assertTrue($result->data->isEmpty());
    }

    public function test_both_sem_exclusao_nao_consulta_o_banco(): void
    {
        $action = $this->actionQueNaoPodeConsultar(
            includeKeywords: ['limpeza'],
            excludeKeywords: [],
        );

        $result = $action->listByUserId(2, new ListRelevantContratationsDTO(), KeywordMatchMode::Both);

        $this->assertSame(0, $result->count);
    }

    /**
     * @param list<string> $includeKeywords
     * @param list<string> $excludeKeywords
     * @param list<string>|null $expectedInclude
     * @param list<string>|null $expectedExclude
     */
    private function actionComPalavras(
        array $includeKeywords,
        array $excludeKeywords,
        ?array $expectedInclude = null,
        ?array $expectedExclude = null
    ): ListRelevantContratationsAction {
        $expectedInclude ??= $includeKeywords;
        $expectedExclude ??= [];

        $filterService = Mockery::mock(UserContratationFilterService::class);
        $filterService->shouldReceive('listGroupedByType')->once()->andReturn([]);

        $keywordService = Mockery::mock(UserKeywordService::class);
        $keywordService->shouldReceive('listGroupedByType')->once()->andReturn([
            UserKeyword::TYPE_INCLUDE => $includeKeywords,
            UserKeyword::TYPE_EXCLUDE => $excludeKeywords,
        ]);

        $contratationsService = Mockery::mock(PublicContratationsService::class);
        $contratationsService->shouldReceive('getPersonalized')
            ->once()
            ->withArgs(function (PersonalizedContratationFiltersDTO $filters) use ($expectedInclude, $expectedExclude) {
                return $filters->includeKeywords === $expectedInclude
                    && $filters->excludeKeywords === $expectedExclude;
            })
            ->andReturn($this->emptyPage());

        return new ListRelevantContratationsAction(
            $contratationsService,
            $filterService,
            $keywordService
        );
    }

    /**
     * @param list<string> $includeKeywords
     * @param list<string> $excludeKeywords
     */
    private function actionQueNaoPodeConsultar(array $includeKeywords, array $excludeKeywords): ListRelevantContratationsAction
    {
        $filterService = Mockery::mock(UserContratationFilterService::class);
        $filterService->shouldReceive('listGroupedByType')->once()->andReturn(['uf' => ['SP']]);

        $keywordService = Mockery::mock(UserKeywordService::class);
        $keywordService->shouldReceive('listGroupedByType')->once()->andReturn([
            UserKeyword::TYPE_INCLUDE => $includeKeywords,
            UserKeyword::TYPE_EXCLUDE => $excludeKeywords,
        ]);

        $contratationsService = Mockery::mock(PublicContratationsService::class);
        $contratationsService->shouldNotReceive('getPersonalized');

        return new ListRelevantContratationsAction(
            $contratationsService,
            $filterService,
            $keywordService
        );
    }

    private function emptyPage(): PaginatedResultDTO
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
