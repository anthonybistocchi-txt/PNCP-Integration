<?php

namespace App\Integrations\PNCP;

use Illuminate\Support\Facades\Cache;

class PncpSyncCycleTracker
{
    private const TTL_HOURS = 24;

    /**
     * Inicializa o contador do ciclo. Deve ser chamado pelo Job Mestre
     * imediatamente antes de enfileirar os operários.
     */
    public function start(string $syncId, int $totalPages): void
    {
        Cache::put($this->doneKey($syncId), 0, now()->addHours(self::TTL_HOURS));
        Cache::put($this->totalKey($syncId), $totalPages, now()->addHours(self::TTL_HOURS));
    }

    /**
     * Marca uma página como encerrada (sucesso, sem dados ou falha permanente).
     * Não deve ser chamado em release/retry.
     *
     * @return int|null Quantidade de páginas concluídas se o ciclo inteiro acabou; null caso contrário.
     */
    public function markPageDone(string $syncId): ?int
    {
        if ($syncId === '') {
            return null;
        }

        $done  = (int) Cache::increment($this->doneKey($syncId));
        $total = (int) Cache::get($this->totalKey($syncId), 0);

        if ($total > 0 && $done === $total) {
            return $done;
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
}
