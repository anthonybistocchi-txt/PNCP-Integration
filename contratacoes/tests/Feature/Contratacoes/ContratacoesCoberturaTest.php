<?php

namespace Tests\Feature\Contratacoes;

use App\Integrations\PNCP\ContratacoesCobertura;
use App\Models\Contratacoes\CicloContratacao;
use App\Models\Contratacoes\CoberturaContratacao;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContratacoesCoberturaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['pncp.contratacoes_recheck_days' => 3]);
        $this->travelTo('2026-10-22 10:00:00');
    }

    public function test_dia_sem_linha_entra_na_proxima_rodada(): void
    {
        $abertos = app(ContratacoesCobertura::class)->intervalosEmAberto([6]);

        $this->assertSame([
            ['modalidade' => 6, 'data_inicial' => '20261019', 'data_final' => '20261020'],
            ['modalidade' => 6, 'data_inicial' => '20261020', 'data_final' => '20261021'],
            ['modalidade' => 6, 'data_inicial' => '20261021', 'data_final' => '20261022'],
        ], $abertos);

        $this->assertSame('20261020', CoberturaContratacao::query()->find(6)->desde);
        $this->assertNull(CoberturaContratacao::query()->find(6)->coberto_ate);
    }

    public function test_dia_fechado_avanca_o_cursor_e_o_buraco_seguinte_continua(): void
    {
        $this->ciclo(6, '20261019', '20261020', CicloContratacao::STATUS_EMPTY, 0, 0);

        $abertos = app(ContratacoesCobertura::class)->intervalosEmAberto([6]);

        $this->assertSame([
            ['modalidade' => 6, 'data_inicial' => '20261020', 'data_final' => '20261021'],
            ['modalidade' => 6, 'data_inicial' => '20261021', 'data_final' => '20261022'],
        ], $abertos);
        $this->assertSame('20261020', CoberturaContratacao::query()->find(6)->coberto_ate);
    }

    public function test_sondagem_em_andamento_nao_e_redespachada(): void
    {
        $this->ciclo(6, '20261019', '20261020', CicloContratacao::STATUS_EMPTY, 0, 0);
        $this->ciclo(6, '20261020', '20261021', CicloContratacao::STATUS_STARTED, 40, 1, now()->subHour());

        $abertos = app(ContratacoesCobertura::class)->intervalosEmAberto([6]);

        $this->assertSame([
            ['modalidade' => 6, 'data_inicial' => '20261021', 'data_final' => '20261022'],
        ], $abertos);
        $this->assertSame('20261020', CoberturaContratacao::query()->find(6)->coberto_ate);
    }

    public function test_rechecagem_so_devolve_janela_que_ja_fechou(): void
    {
        $this->ciclo(6, '20261021', '20261022', CicloContratacao::STATUS_FINISHED, 10, 10);

        $recheck = app(ContratacoesCobertura::class)->intervalosParaRechecagem([6]);

        $this->assertSame([
            ['modalidade' => 6, 'data_inicial' => '20261021', 'data_final' => '20261022'],
        ], $recheck);
    }

    public function test_gap_antigo_deixa_de_ser_reprocessado_quando_um_ciclo_posterior_fecha(): void
    {
        $this->ciclo(6, '20260901', '20260902', CicloContratacao::STATUS_ABANDONED, 0, 0, now()->subDays(20));
        $this->ciclo(6, '20260901', '20260902', CicloContratacao::STATUS_FINISHED, 5, 5, now()->subDays(19));

        $this->assertTrue(app(ContratacoesCobertura::class)->temSucesso(6, '20260901', '20260902'));
    }

    private function ciclo(
        int $modalidade,
        string $inicial,
        string $final,
        string $status,
        int $anunciado,
        int $processados,
        ?\DateTimeInterface $startedAt = null
    ): void {
        CicloContratacao::query()->create([
            'sync_id'           => (string) Str::uuid(),
            'modalidade'        => $modalidade,
            'data_inicial'      => $inicial,
            'data_final'        => $final,
            'total_anunciado'   => $anunciado,
            'total_paginas'     => $anunciado > 0 ? 1 : 0,
            'itens_processados' => $processados,
            'paginas_completas' => $status === CicloContratacao::STATUS_FINISHED ? 1 : 0,
            'status'            => $status,
            'started_at'        => $startedAt ?? now()->subDays(2),
            'finished_at'       => $status === CicloContratacao::STATUS_STARTED ? null : now()->subDay(),
        ]);
    }
}
