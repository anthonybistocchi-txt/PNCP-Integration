<?php

namespace Tests\Feature\Integrations;

use App\Integrations\PNCP\PncpSyncCycleTracker;
use Tests\TestCase;

class PncpSyncCycleTrackerTest extends TestCase
{
    public function test_each_unit_counts_once_no_matter_how_many_paths_close_it()
    {
        $tracker = $this->app->make(PncpSyncCycleTracker::class);
        $tracker->start('sync', 3, 9);

        $this->assertNull($tracker->markPageDone('sync', 3, '1'));
        // Mesma página encerrada por um segundo caminho (fail() + catch, reentrega).
        $this->assertNull($tracker->markPageDone('sync', 3, '1'));
        $this->assertNull($tracker->markPageDone('sync', 0, '1'));

        // Ainda faltam as páginas 2 e 3: o ciclo não pode ter fechado.
        $this->assertNull($tracker->markPageDone('sync', 3, '2'));

        $summary = $tracker->markPageDone('sync', 3, '3');

        $this->assertSame(
            ['units_completed' => 3, 'items_processed' => 9, 'total_records' => 9],
            $summary
        );
    }

    public function test_the_summary_is_replayed_for_an_already_counted_unit_once_the_cycle_is_complete()
    {
        $tracker = $this->app->make(PncpSyncCycleTracker::class);
        $tracker->start('sync', 2, 5);

        $tracker->markPageDone('sync', 3, '1');
        $summary = $tracker->markPageDone('sync', 2, '2');

        $this->assertNotNull($summary);
        // Quem recebeu o resumo pode ter falhado ao gravá-lo: a reentrega o recebe de novo.
        $this->assertSame($summary, $tracker->markPageDone('sync', 2, '2'));
    }

    public function test_is_started_reflects_the_counters()
    {
        $tracker = $this->app->make(PncpSyncCycleTracker::class);

        $this->assertFalse($tracker->isStarted('sync'));

        $tracker->start('sync', 2, 5);

        $this->assertTrue($tracker->isStarted('sync'));
    }

    public function test_without_a_unit_key_every_call_counts()
    {
        $tracker = $this->app->make(PncpSyncCycleTracker::class);
        $tracker->start('sync', 2, 2);

        $this->assertNull($tracker->markPageDone('sync', 1));
        $this->assertNotNull($tracker->markPageDone('sync', 1));
    }
}
