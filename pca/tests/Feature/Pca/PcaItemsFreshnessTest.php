<?php

namespace Tests\Feature\Pca;

use App\Models\Pca\PncpPcaCiclo;
use App\Support\Pca\PcaItemsFreshness;
use Illuminate\Support\Str;
use Tests\TestCase;

class PcaItemsFreshnessTest extends TestCase
{
    public function test_never_synced_is_pending()
    {
        $this->assertTrue(PcaItemsFreshness::isPending(null, null));
        $this->assertTrue(PcaItemsFreshness::isPending(null, now()->subYear()));
    }

    public function test_up_to_date_when_not_updated_after_the_download()
    {
        $baixadoEm = now()->subDay();

        $this->assertFalse(PcaItemsFreshness::isPending($baixadoEm, $baixadoEm->copy()->subDay(), 30));
        $this->assertFalse(PcaItemsFreshness::isPending($baixadoEm, null, 30));
        // Mesma data: o download começou depois (ou junto) da atualização.
        $this->assertFalse(PcaItemsFreshness::isPending($baixadoEm, $baixadoEm->copy(), 30));
    }

    public function test_pending_when_the_plan_was_updated_after_the_download_started()
    {
        $baixadoEm = now()->subDay();

        $this->assertTrue(PcaItemsFreshness::isPending($baixadoEm, $baixadoEm->copy()->addMinute(), 30));
    }

    public function test_pending_when_the_watermark_is_older_than_the_refresh_window()
    {
        $this->assertTrue(PcaItemsFreshness::isPending(now()->subDays(31), now()->subDays(90), 30));
        $this->assertFalse(PcaItemsFreshness::isPending(now()->subDays(29), now()->subDays(90), 30));
        // refreshDays = 0 desliga a rede de segurança.
        $this->assertFalse(PcaItemsFreshness::isPending(now()->subDays(400), now()->subDays(500), 0));
    }

    public function test_latest_update_picks_the_most_recent_valid_date()
    {
        $latest = PcaItemsFreshness::latestUpdate('2026-09-30T15:49:15', null, '', '2026-10-02T09:00:00', 'lixo-nao-data');

        $this->assertSame('2026-10-02 09:00:00', $latest->format('Y-m-d H:i:s'));
        $this->assertNull(PcaItemsFreshness::latestUpdate(null, ''));
    }

    public function test_gap_scope_matches_the_three_definitions_and_ignores_organ_cycles()
    {
        $this->ciclo(1, 'abandoned');
        $this->ciclo(2, 'finished', anunciado: 10, processados: 7);   // incompleto
        $this->ciclo(3, 'finished', anunciado: 10, processados: 10);  // ok
        $this->ciclo(4, 'started', startedAt: now()->subDays(4));     // job já venceu
        $this->ciclo(5, 'started', startedAt: now()->subHours(2));    // ainda vivo
        $this->ciclo(null, 'abandoned');                              // ciclo de órgão (mestre)

        $gap = PncpPcaCiclo::emGap(72)->pluck('sequencial_pca')->all();
        sort($gap);
        $this->assertSame([1, 2, 4], $gap);

        $this->assertSame([5], PncpPcaCiclo::emAndamento(72)->pluck('sequencial_pca')->all());
    }

    private function ciclo(?int $seq, string $status, int $anunciado = 0, int $processados = 0, $startedAt = null): void
    {
        PncpPcaCiclo::create([
            'sync_id'           => (string) Str::uuid(),
            'cnpj_orgao'        => '12345678000199',
            'ano_pca'           => 2026,
            'sequencial_pca'    => $seq,
            'total_anunciado'   => $anunciado,
            'itens_processados' => $processados,
            'status'            => $status,
            'started_at'        => $startedAt ?? now()->subDays(10),
        ]);
    }
}
