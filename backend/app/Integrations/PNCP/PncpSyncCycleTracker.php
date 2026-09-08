<?php

namespace App\Integrations\PNCP;

use Illuminate\Support\Facades\Cache;

class PncpSyncCycleTracker
{
    private const TTL_HOURS = 24;

    /**
     * Inicializa o contador do ciclo. Deve ser chamado pelo Job Mestre
     * imediatamente antes de enfileirar os operários.
     *
     * @param  int  $totalUnits  Páginas (listagem) ou lotes (documentos).
     * @param  int  $totalRecords  Total de registros esperados no ciclo (para o log de fim).
     */
    public function start(string $syncId, int $totalUnits, int $totalRecords = 0): void
    {
        $ttl = now()->addHours(self::TTL_HOURS);

        Cache::put($this->doneKey($syncId), 0, $ttl);
        Cache::put($this->totalKey($syncId), $totalUnits, $ttl);
        Cache::put($this->recordsKey($syncId), $totalRecords, $ttl);
        Cache::put($this->itemsKey($syncId), 0, $ttl);
    }

    /**
     * Marca uma unidade (página/lote) como encerrada e acumula itens processados.
     * Não deve ser chamado em release/retry.
     *
     * @return array{units_completed: int, items_processed: int, total_records: int}|null
     *         Resumo do ciclo quando a última unidade termina; null caso contrário.
     */
    public function markPageDone(string $syncId, int $itemsProcessed = 0): ?array
    {
        if ($syncId === '') {
            return null;
        }

        if ($itemsProcessed > 0) {
            Cache::increment($this->itemsKey($syncId), $itemsProcessed);
        }

        $done  = (int) Cache::increment($this->doneKey($syncId));
        $total = (int) Cache::get($this->totalKey($syncId), 0);

        if ($total > 0 && $done === $total) {
            return [
                'units_completed' => $done,
                'items_processed' => (int) Cache::get($this->itemsKey($syncId), 0),
                'total_records'   => (int) Cache::get($this->recordsKey($syncId), 0),
            ];
        }

        return null;
    }

    private function doneKey(string $syncId): string
    {
        return "pncp_sync:{$syncId}:done";
    }

    private function totalKey(string $syncId): string
    {
        return "pncp_sync:{$syncId}:total";
    }

    private function recordsKey(string $syncId): string
    {
        return "pncp_sync:{$syncId}:records";
    }

    private function itemsKey(string $syncId): string
    {
        return "pncp_sync:{$syncId}:items";
    }
}
