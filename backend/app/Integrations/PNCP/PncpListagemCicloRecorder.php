<?php

namespace App\Integrations\PNCP;

use App\Models\PublicContratations\PncpListagemCiclo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Auditoria durável dos ciclos de listagem (Postgres).
 *
 * Separado do PncpSyncCycleTracker (Redis): aquele conta páginas em voo para
 * emitir FINISHED e liberar a Fase 2; este persiste anunciado vs processado
 * para detectar gaps depois que o laravel.log já foi truncado.
 */
class PncpListagemCicloRecorder
{
    public function start(
        string $syncId,
        int $modalidade,
        string $dataInicial,
        string $dataFinal,
        int $totalAnunciado,
        int $totalPaginas,
        ?\DateTimeInterface $startedAt = null
    ): PncpListagemCiclo {
        return PncpListagemCiclo::query()->updateOrCreate(
            ['sync_id' => $syncId],
            [
                'modalidade'        => $modalidade,
                'data_inicial'      => $dataInicial,
                'data_final'        => $dataFinal,
                'total_anunciado'   => $totalAnunciado,
                'total_paginas'     => $totalPaginas,
                'items_processados' => 0,
                'paginas_completas' => 0,
                'status'            => PncpListagemCiclo::STATUS_STARTED,
                'started_at'        => $startedAt ?? now(),
                'finished_at'       => null,
            ]
        );
    }

    /**
     * Listagem sem registros: grava um ciclo já fechado como empty.
     * Gera sync_id próprio para manter a coluna unique utilizável.
     */
    public function recordEmpty(
        int $modalidade,
        string $dataInicial,
        string $dataFinal,
        ?\DateTimeInterface $startedAt = null
    ): PncpListagemCiclo {
        $now = now();

        return PncpListagemCiclo::query()->create([
            'sync_id'           => (string) Str::uuid(),
            'modalidade'        => $modalidade,
            'data_inicial'      => $dataInicial,
            'data_final'        => $dataFinal,
            'total_anunciado'   => 0,
            'total_paginas'     => 0,
            'items_processados' => 0,
            'paginas_completas' => 0,
            'status'            => PncpListagemCiclo::STATUS_EMPTY,
            'started_at'        => $startedAt ?? $now,
            'finished_at'       => $now,
        ]);
    }

    public function finish(
        string $syncId,
        int $itemsProcessados,
        int $paginasCompletas,
        string $status = PncpListagemCiclo::STATUS_FINISHED
    ): void {
        if ($syncId === '') {
            return;
        }

        $updated = PncpListagemCiclo::query()
            ->where('sync_id', $syncId)
            ->whereIn('status', [PncpListagemCiclo::STATUS_STARTED, PncpListagemCiclo::STATUS_ABANDONED])
            ->update([
                'items_processados' => $itemsProcessados,
                'paginas_completas' => $paginasCompletas,
                'status'            => $status,
                'finished_at'       => now(),
                'updated_at'        => now(),
            ]);

        if ($updated === 0) {
            Log::warning('PNCP_LISTAGEM_CICLO_FINISH_MISSED', [
                'sync_id'           => $syncId,
                'items_processados' => $itemsProcessados,
                'paginas_completas' => $paginasCompletas,
                'status'            => $status,
                'reason'            => 'Nenhuma linha started/abandoned encontrada para este sync_id.',
            ]);
        }
    }

    /**
     * Marca o ciclo como abandonado se ainda estiver started.
     * Não sobrescreve finished/empty.
     */
    public function abandon(string $syncId, ?string $reason = null): void
    {
        if ($syncId === '') {
            return;
        }

        $updated = PncpListagemCiclo::query()
            ->where('sync_id', $syncId)
            ->where('status', PncpListagemCiclo::STATUS_STARTED)
            ->update([
                'status'      => PncpListagemCiclo::STATUS_ABANDONED,
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);

        if ($updated > 0) {
            Log::warning('PNCP_LISTAGEM_CICLO_ABANDONED', [
                'sync_id' => $syncId,
                'reason'  => $reason,
            ]);
        }
    }
}
