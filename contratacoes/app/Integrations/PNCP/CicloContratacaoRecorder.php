<?php

namespace App\Integrations\PNCP;

use App\Models\Contratacoes\CicloContratacao;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Auditoria durável dos ciclos de contratacoes (Postgres).
 *
 * Separado do PncpSyncCycleTracker (Redis): aquele conta páginas em voo para
 * emitir FINISHED e liberar a Fase 2; este persiste anunciado vs processado
 * para detectar gaps depois que o laravel.log já foi truncado.
 */
class CicloContratacaoRecorder
{
    public function start(
        string $syncId,
        int $modalidade,
        string $dataInicial,
        string $dataFinal,
        int $totalAnunciado,
        int $totalPaginas,
        ?\DateTimeInterface $startedAt = null
    ): CicloContratacao {
        return CicloContratacao::query()->updateOrCreate(
            ['sync_id' => $syncId],
            [
                'modalidade'        => $modalidade,
                'data_inicial'      => $dataInicial,
                'data_final'        => $dataFinal,
                'total_anunciado'   => $totalAnunciado,
                'total_paginas'     => $totalPaginas,
                'itens_processados' => 0,
                'paginas_completas' => 0,
                'status'            => CicloContratacao::STATUS_STARTED,
                'started_at'        => $startedAt ?? now(),
                'finished_at'       => null,
            ]
        );
    }

    /**
     * Contratacoes sem registros: grava um ciclo já fechado como empty.
     * Gera sync_id próprio para manter a coluna unique utilizável.
     */
    public function recordEmpty(
        int $modalidade,
        string $dataInicial,
        string $dataFinal,
        ?\DateTimeInterface $startedAt = null
    ): CicloContratacao {
        $now = now();

        return CicloContratacao::query()->create([
            'sync_id'           => (string) Str::uuid(),
            'modalidade'        => $modalidade,
            'data_inicial'      => $dataInicial,
            'data_final'        => $dataFinal,
            'total_anunciado'   => 0,
            'total_paginas'     => 0,
            'itens_processados' => 0,
            'paginas_completas' => 0,
            'status'            => CicloContratacao::STATUS_EMPTY,
            'started_at'        => $startedAt ?? $now,
            'finished_at'       => $now,
        ]);
    }

    public function finish(
        string $syncId,
        int $itemsProcessados,
        int $paginasCompletas,
        string $status = CicloContratacao::STATUS_FINISHED
    ): void {
        if ($syncId === '') {
            return;
        }

        $updated = CicloContratacao::query()
            ->where('sync_id', $syncId)
            ->whereIn('status', [CicloContratacao::STATUS_STARTED, CicloContratacao::STATUS_ABANDONED])
            ->update([
                'itens_processados' => $itemsProcessados,
                'paginas_completas' => $paginasCompletas,
                'status'            => $status,
                'finished_at'       => now(),
                'updated_at'        => now(),
            ]);

        if ($updated === 0) {
            Log::warning('PNCP_CONTRATACOES_CICLO_FINISH_MISSED', [
                'sync_id'           => $syncId,
                'itens_processados' => $itemsProcessados,
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

        $updated = CicloContratacao::query()
            ->where('sync_id', $syncId)
            ->where('status', CicloContratacao::STATUS_STARTED)
            ->update([
                'status'      => CicloContratacao::STATUS_ABANDONED,
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);

        if ($updated > 0) {
            Log::warning('PNCP_CONTRATACOES_CICLO_ABANDONED', [
                'sync_id' => $syncId,
                'reason'  => $reason,
            ]);
        }
    }
}
