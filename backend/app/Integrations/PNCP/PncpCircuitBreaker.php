<?php

namespace App\Integrations\PNCP;

use Illuminate\Support\Facades\Cache;

class PncpCircuitBreaker
{
    private const CACHE_KEY = 'pncp_circuit_breaker_until';

    /**
     * Verifica se o Circuit Breaker está aberto (bloqueando requisições).
     */
    public function isOpen(): bool
    {
        return Cache::has(self::CACHE_KEY) && $this->remainingSeconds() > 0;
    }

    /**
     * Retorna quantos segundos faltam para o Circuit Breaker fechar.
     */
    public function remainingSeconds(): int
    {
        $pauseUntil = Cache::get(self::CACHE_KEY, 0);
        return max(0, $pauseUntil - now()->timestamp);
    }

    /**
     * Abre o Circuit Breaker por $minutes minutos.
     */
    public function trip(int $minutes = 5): void
    {
        $pauseUntil = now()->addMinutes($minutes)->timestamp;
        Cache::put(self::CACHE_KEY, $pauseUntil, now()->addMinutes($minutes));
    }
}
