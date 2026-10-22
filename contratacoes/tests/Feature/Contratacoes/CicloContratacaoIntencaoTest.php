<?php

namespace Tests\Feature\Contratacoes;

use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\Integrations\PNCP\CicloContratacaoRecorder;
use App\Jobs\Contratacoes\ProcessContratacaoPageJob;
use App\Jobs\Contratacoes\SyncContratacoesJob;
use App\Models\Contratacoes\CicloContratacao;
use App\Repositories\Contratacoes\CicloContratacaoRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CicloContratacaoIntencaoTest extends TestCase
{
    public function test_dispatch_grava_ciclo_antes_do_job_rodar(): void
    {
        Bus::fake();
        config(['pncp.contratacoes_recheck_days' => 1]);
        $this->travelTo('2026-10-22 10:00:00');

        $this->artisan('pncp:sync-contratacoes', [
            '--data-inicial' => '20261001',
            '--data-final'   => '20261002',
            '--modalidades'  => '6',
        ])->assertSuccessful();

        $this->assertDatabaseHas('ciclos_contratacao', [
            'modalidade'      => 6,
            'data_inicial'    => '20261001',
            'data_final'      => '20261002',
            'status'          => CicloContratacao::STATUS_STARTED,
            'total_anunciado' => 0,
            'total_paginas'   => 0,
        ]);

        $this->assertDatabaseHas('ciclos_contratacao', [
            'modalidade'   => 6,
            'data_inicial' => '20261021',
            'data_final'   => '20261022',
            'status'       => CicloContratacao::STATUS_STARTED,
        ]);

        Bus::assertDispatched(SyncContratacoesJob::class);
    }

    public function test_resposta_vazia_fecha_a_mesma_linha_como_empty(): void
    {
        Http::fake([
            'pncp.gov.br/*' => Http::response(['data' => []], 200),
        ]);
        Bus::fake();

        $this->runMaster($this->dto());

        $ciclo = CicloContratacao::query()->firstOrFail();

        $this->assertSame(CicloContratacao::STATUS_EMPTY, $ciclo->status);
        $this->assertNotNull($ciclo->finished_at);
        $this->assertSame(1, CicloContratacao::query()->count());
        Bus::assertNotDispatched(ProcessContratacaoPageJob::class);
    }

    public function test_resposta_com_dados_preenche_o_total_na_mesma_linha(): void
    {
        Http::fake([
            'pncp.gov.br/*' => Http::response([
                'data'            => [['numeroControlePNCP' => '1']],
                'totalPaginas'    => 2,
                'totalRegistros'  => 60,
            ], 200),
        ]);
        Bus::fake();

        $this->runMaster($this->dto());

        $ciclo = CicloContratacao::query()->firstOrFail();

        $this->assertSame(CicloContratacao::STATUS_STARTED, $ciclo->status);
        $this->assertSame(60, $ciclo->total_anunciado);
        $this->assertSame(2, $ciclo->total_paginas);
        $this->assertSame(1, CicloContratacao::query()->count());
        Bus::assertDispatched(ProcessContratacaoPageJob::class, 2);
    }

    public function test_failed_abandona_ciclo_sem_total_e_o_reconciliador_enxerga(): void
    {
        $dto = $this->dto();

        app(CicloContratacaoRecorder::class)->intend(
            $dto->codigoModalidadeContratacao,
            $dto->dataInicial,
            $dto->dataFinal,
        );

        CicloContratacao::query()->create([
            'sync_id'           => '11111111-1111-1111-1111-111111111111',
            'modalidade'        => 6,
            'data_inicial'      => '20261001',
            'data_final'        => '20261002',
            'total_anunciado'   => 40,
            'total_paginas'     => 1,
            'itens_processados' => 0,
            'paginas_completas' => 0,
            'status'            => CicloContratacao::STATUS_STARTED,
            'started_at'        => now(),
        ]);

        (new SyncContratacoesJob($dto))->failed(new \RuntimeException('esgotou'));

        $this->assertDatabaseHas('ciclos_contratacao', [
            'total_anunciado' => 0,
            'status'          => CicloContratacao::STATUS_ABANDONED,
        ]);
        $this->assertDatabaseHas('ciclos_contratacao', [
            'sync_id' => '11111111-1111-1111-1111-111111111111',
            'status'  => CicloContratacao::STATUS_STARTED,
        ]);

        $intervalos = app(CicloContratacaoRepository::class)->intervalosIncompletos(12);

        $this->assertTrue($intervalos->contains(
            fn ($ciclo) => (int) $ciclo->modalidade === 6
                && $ciclo->data_inicial === '20261001'
                && $ciclo->data_final === '20261002'
        ));
    }

    public function test_ciclo_started_recente_sem_total_ainda_nao_e_gap(): void
    {
        $dto = $this->dto();

        app(CicloContratacaoRecorder::class)->intend(
            $dto->codigoModalidadeContratacao,
            $dto->dataInicial,
            $dto->dataFinal,
        );

        $intervalos = app(CicloContratacaoRepository::class)->intervalosIncompletos(12);

        $this->assertTrue($intervalos->isEmpty());
    }

    public function test_ciclo_started_sem_total_alem_do_retry_e_gap(): void
    {
        $dto = $this->dto();

        app(CicloContratacaoRecorder::class)->intend(
            $dto->codigoModalidadeContratacao,
            $dto->dataInicial,
            $dto->dataFinal,
            now()->subHours(13),
        );

        $intervalos = app(CicloContratacaoRepository::class)->intervalosIncompletos(12);

        $this->assertCount(1, $intervalos);
        $this->assertSame(6, (int) $intervalos->first()->modalidade);
    }

    private function dto(): ListContratacoesDTO
    {
        return new ListContratacoesDTO(
            dataInicial: '20261001',
            dataFinal: '20261002',
            codigoModalidadeContratacao: 6,
        );
    }

    private function runMaster(ListContratacoesDTO $dto): void
    {
        app()->call([new SyncContratacoesJob($dto), 'handle']);
    }
}
