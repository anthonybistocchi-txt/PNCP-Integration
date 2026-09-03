<?php

namespace Tests\Unit\Support\Search;

use App\Models\PublicContratations\PublicContratation;
use App\Support\Search\ContratationKeywordFilter;
use Tests\TestCase;

class ContratationKeywordFilterTest extends TestCase
{
    private ContratationKeywordFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new ContratationKeywordFilter();
    }

    public function test_so_inclusao_aplica_fts_sem_negacao(): void
    {
        $query = PublicContratation::query();

        $this->filter->apply($query, ['limpeza predial', 'software'], []);

        $sql = $query->toSql();

        $this->assertStringContainsString("to_tsvector('portuguese', objeto_compra) @@", $sql);
        $this->assertStringContainsString("phraseto_tsquery('portuguese', ?)", $sql);
        $this->assertStringNotContainsString('NOT (', $sql);
        $this->assertSame(['limpeza predial', 'software'], $query->getBindings());
    }

    public function test_so_exclusao_nega_o_fts(): void
    {
        $query = PublicContratation::query();

        $this->filter->apply($query, [], ['carro']);

        $sql = $query->toSql();

        $this->assertStringContainsString('NOT (', $sql);
        $this->assertStringContainsString("to_tsvector('portuguese', objeto_compra) @@", $sql);
        $this->assertSame(['carro'], $query->getBindings());
    }

    public function test_ambos_sao_and_independentes_e_nao_de_morgan(): void
    {
        $query = PublicContratation::query();

        $this->filter->apply($query, ['limpeza'], ['carro']);

        $sql = $query->toSql();

        $this->assertStringContainsString("to_tsvector('portuguese', objeto_compra) @@", $sql);
        $this->assertStringContainsString('NOT (', $sql);
        $this->assertStringNotContainsString('NOT ((', $sql);
        $this->assertSame(['limpeza', 'carro'], $query->getBindings());
    }

    public function test_listas_vazias_nao_alteram_a_query(): void
    {
        $query = PublicContratation::query();

        $this->filter->apply($query, [], []);

        $this->assertSame('select * from "public_contratations"', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }
}
