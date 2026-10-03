<?php

namespace App\Repositories\Pca;

use App\Models\Pca\PncpPcaCiclo;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PncpPcaCicloRepository
{
    public function createOrgaoStarted(string $syncId, string $cnpj, int $ano, DateTimeInterface $startedAt): void
    {
        PncpPcaCiclo::query()->create([
            'sync_id'    => $syncId,
            'cnpj_orgao' => $cnpj,
            'ano_pca'    => $ano,
            'status'     => PncpPcaCiclo::STATUS_STARTED,
            'started_at' => $startedAt,
        ]);
    }

    /**
     * Plano sem itens: ciclo já fechado. O sync_id é só para a unique.
     */
    public function createPlanEmpty(string $cnpj, int $ano, int $sequencial, DateTimeInterface $startedAt): void
    {
        $now = now();

        PncpPcaCiclo::query()->create([
            'sync_id'        => (string) Str::uuid(),
            'cnpj_orgao'     => $cnpj,
            'ano_pca'        => $ano,
            'sequencial_pca' => $sequencial,
            'status'         => PncpPcaCiclo::STATUS_EMPTY,
            'started_at'     => $startedAt,
            'finished_at'    => $now,
        ]);
    }

    public function createPlanStarted(
        string $syncId,
        string $cnpj,
        int $ano,
        int $sequencial,
        int $totalAnunciado,
        int $totalPaginas,
        DateTimeInterface $startedAt
    ): void {
        PncpPcaCiclo::query()->create([
            'sync_id'         => $syncId,
            'cnpj_orgao'      => $cnpj,
            'ano_pca'         => $ano,
            'sequencial_pca'  => $sequencial,
            'total_anunciado' => $totalAnunciado,
            'total_paginas'   => $totalPaginas,
            'status'          => PncpPcaCiclo::STATUS_STARTED,
            'started_at'      => $startedAt,
        ]);
    }

    /**
     * O ciclo de plano nem chegou a medir a quantidade (ex.: API fora do ar
     * durante todo o retryUntil). Registra abandoned para o gap ficar visível.
     */
    public function createPlanAbandoned(string $cnpj, int $ano, int $sequencial): void
    {
        $now = now();

        PncpPcaCiclo::query()->create([
            'sync_id'        => (string) Str::uuid(),
            'cnpj_orgao'     => $cnpj,
            'ano_pca'        => $ano,
            'sequencial_pca' => $sequencial,
            'status'         => PncpPcaCiclo::STATUS_ABANDONED,
            'started_at'     => $now,
            'finished_at'    => $now,
        ]);
    }

    public function findBySyncId(string $syncId): ?PncpPcaCiclo
    {
        return PncpPcaCiclo::query()->where('sync_id', $syncId)->first();
    }

    public function markEmpty(string $syncId): void
    {
        PncpPcaCiclo::query()->where('sync_id', $syncId)->update([
            'status'      => PncpPcaCiclo::STATUS_EMPTY,
            'finished_at' => now(),
        ]);
    }

    /**
     * Encerra o ciclo de órgão assim que o fan-out dos planos termina.
     */
    public function markFinished(string $syncId): void
    {
        PncpPcaCiclo::query()->where('sync_id', $syncId)->update([
            'status'      => PncpPcaCiclo::STATUS_FINISHED,
            'finished_at' => now(),
        ]);
    }

    /**
     * Fecha o ciclo de plano só se ainda estiver started. Não sobrescreve
     * empty/abandoned/finished.
     */
    public function finishPlanIfStarted(string $syncId, int $paginasCompletas, int $itensProcessados): void
    {
        PncpPcaCiclo::query()
            ->where('sync_id', $syncId)
            ->where('status', PncpPcaCiclo::STATUS_STARTED)
            ->update([
                'status'            => PncpPcaCiclo::STATUS_FINISHED,
                'finished_at'       => now(),
                'paginas_completas' => $paginasCompletas,
                'itens_processados' => $itensProcessados,
            ]);
    }

    /**
     * Marca abandoned só o que ainda está started. Não sobrescreve finished/empty.
     */
    public function abandonIfStarted(string $syncId): void
    {
        PncpPcaCiclo::query()
            ->where('sync_id', $syncId)
            ->where('status', PncpPcaCiclo::STATUS_STARTED)
            ->update([
                'status'      => PncpPcaCiclo::STATUS_ABANDONED,
                'finished_at' => now(),
            ]);
    }

    /**
     * Sequenciais com ciclo started ainda dentro do prazo: páginas que podem
     * estar na fila. Despachar de novo duplicaria o download.
     *
     * @return Collection<int|string, mixed>
     */
    public function sequenciaisEmAndamento(string $cnpj, int $ano): Collection
    {
        return PncpPcaCiclo::emAndamento()
            ->where('cnpj_orgao', $cnpj)
            ->where('ano_pca', $ano)
            ->pluck('sequencial_pca')
            ->flip();
    }

    /**
     * Chaves `cnpj:sequencial` de ciclos de plano ainda vivos no ano.
     *
     * @return array<string, true>
     */
    public function chavesEmAndamento(int $ano, ?string $cnpj, int $retryHours): array
    {
        return PncpPcaCiclo::emAndamento($retryHours)
            ->where('ano_pca', $ano)
            ->when($cnpj, fn ($query, $cnpj) => $query->where('cnpj_orgao', $cnpj))
            ->get(['cnpj_orgao', 'sequencial_pca'])
            ->mapWithKeys(fn ($ciclo) => ["{$ciclo->cnpj_orgao}:{$ciclo->sequencial_pca}" => true])
            ->all();
    }

    /**
     * Resumo histórico de ciclos de plano em gap, agrupado por status.
     * Pode incluir ciclos antigos de planos já recuperados depois.
     */
    public function resumoGaps(int $ano, ?string $cnpj, int $retryHours): Collection
    {
        return PncpPcaCiclo::emGap($retryHours)
            ->where('ano_pca', $ano)
            ->when($cnpj, fn ($query, $cnpj) => $query->where('cnpj_orgao', $cnpj))
            ->selectRaw('status, count(*) as ciclos, coalesce(sum(total_anunciado), 0) as anunciado, coalesce(sum(itens_processados), 0) as processados')
            ->groupBy('status')
            ->orderBy('status')
            ->get();
    }
}
