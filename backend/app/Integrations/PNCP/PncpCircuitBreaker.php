<?php

namespace App\Integrations\PNCP;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pausa global das requisições ao PNCP, compartilhada entre todos os workers.
 *
 * A decisão de quanto esperar depende do tipo de erro:
 *
 *  - 429: a API pediu explicitamente para parar, então abre na primeira
 *    ocorrência e respeita o Retry-After quando ele vem.
 *  - 5xx/timeout: a API é instável por requisição (já houve caso de duas
 *    falhas seguidas e sucesso na terceira com os mesmos parâmetros), então
 *    uma falha isolada não pausa nada; só N consecutivas pausam.
 *
 * Depois que a pausa vence, o breaker fica "meio aberto": um único job pega o
 * lock de sondagem e testa a API. Se ele passar, tudo é liberado; se falhar,
 * a pausa reabre no próximo degrau da escala.
 */
class PncpCircuitBreaker
{
    private const KEY_OPEN_UNTIL = 'pncp_cb:open_until';
    private const KEY_FAILURES   = 'pncp_cb:failures';
    private const KEY_LEVEL      = 'pncp_cb:level';
    private const KEY_PROBE      = 'pncp_cb:probe';

    /** Janela em que falhas ainda contam como "consecutivas". */
    private const FAILURE_WINDOW_MINUTES = 10;

    /** Por quanto tempo lembramos do degrau de escalonamento. */
    private const LEVEL_MEMORY_MINUTES = 60;

    public function isOpen(): bool
    {
        return $this->remainingSeconds() > 0;
    }

    public function remainingSeconds(): int
    {
        return max(0, ((int) Cache::get(self::KEY_OPEN_UNTIL, 0)) - now()->timestamp);
    }

    /**
     * Pausa vencida, mas ainda sem nenhum sucesso confirmando que a API voltou.
     */
    public function isHalfOpen(): bool
    {
        return ! $this->isOpen() && $this->level() > 0;
    }

    /**
     * Só o primeiro job a chamar consegue sondar a API. Os demais devem
     * aguardar, para não repetir a enxurrada assim que a pausa vence.
     */
    public function acquireProbe(): bool
    {
        return Cache::lock(self::KEY_PROBE, (int) config('pncp.breaker.probe_lock_seconds'))->get();
    }

    public function consecutiveFailures(): int
    {
        return (int) Cache::get(self::KEY_FAILURES, 0);
    }

    /**
     * Registra 5xx ou timeout. Abre a pausa apenas quando as falhas
     * consecutivas atingem o limiar, ou quando a sondagem do meio aberto
     * falhou (aí não faz sentido esperar o limiar de novo).
     */
    public function recordServerFailure(): bool
    {
        $wasHalfOpen = $this->isHalfOpen();
        $failures    = $this->bumpFailures();
        $threshold   = max(1, (int) config('pncp.breaker.server_error_threshold'));

        if ($failures < $threshold && ! $wasHalfOpen) {
            return false;
        }

        $this->open(
            seconds: $this->nextBackoffSeconds('pncp.breaker.server_error_backoff'),
            reason: $wasHalfOpen ? 'sondagem_falhou' : 'falhas_consecutivas',
            failures: $failures
        );

        return true;
    }

    /**
     * Registra 429. Abre imediatamente: é a própria API dizendo o quanto
     * deveríamos esperar.
     */
    public function recordRateLimit(?int $retryAfter = null): bool
    {
        $failures = $this->bumpFailures();

        // O degrau avança sempre, mesmo quando a duração vem do header: é ele
        // que marca o meio aberto e faz a próxima pausa ser mais longa.
        $escalonado = $this->nextBackoffSeconds('pncp.breaker.rate_limit_backoff');

        $this->open(
            seconds: $retryAfter !== null && $retryAfter > 0 ? $retryAfter : $escalonado,
            reason: $retryAfter !== null ? 'retry_after_header' : 'rate_limit',
            failures: $failures
        );

        return true;
    }

    /**
     * A API respondeu. Zera contador, escalonamento e pausa.
     */
    public function recordSuccess(): void
    {
        if ($this->isHalfOpen()) {
            Log::info('PNCP_BREAKER_CLOSED', [
                'reason' => 'Sondagem bem-sucedida, requisições liberadas.',
            ]);
        }

        Cache::forget(self::KEY_FAILURES);
        Cache::forget(self::KEY_LEVEL);
        Cache::forget(self::KEY_OPEN_UNTIL);
        Cache::lock(self::KEY_PROBE)->forceRelease();
    }

    /**
     * Abre a pausa por um número fixo de minutos, sem escalonar.
     */
    public function trip(int $minutes = 5): void
    {
        $this->openFor(max(1, $minutes) * 60);
    }

    private function open(int $seconds, string $reason, int $failures): void
    {
        $this->openFor($seconds);

        Log::warning('PNCP_BREAKER_OPENED', [
            'reason'              => $reason,
            'seconds'             => $seconds,
            'level'               => $this->level(),
            'consecutive_failures'=> $failures,
        ]);
    }

    private function openFor(int $seconds): void
    {
        $seconds = max(1, $seconds);

        // A folga no TTL evita uma janela em que a chave expira antes do
        // horário que ela mesma anuncia.
        Cache::put(
            self::KEY_OPEN_UNTIL,
            now()->timestamp + $seconds,
            now()->addSeconds($seconds + 60)
        );

        Cache::forget(self::KEY_FAILURES);

        // Libera o lock para que a próxima janela de meio aberto tenha uma
        // sondagem nova, mesmo que a pausa seja mais curta que o TTL do lock.
        Cache::lock(self::KEY_PROBE)->forceRelease();
    }

    /**
     * Avança um degrau na escala e devolve a pausa correspondente.
     */
    private function nextBackoffSeconds(string $configKey): int
    {
        $scale = (array) config($configKey);

        if ($scale === []) {
            $scale = [(int) config('pncp.circuit_breaker_minutes', 2)];
        }

        $level = min($this->level() + 1, count($scale));

        Cache::put(self::KEY_LEVEL, $level, now()->addMinutes(self::LEVEL_MEMORY_MINUTES));

        return (int) $scale[$level - 1] * 60;
    }

    private function level(): int
    {
        return (int) Cache::get(self::KEY_LEVEL, 0);
    }

    /**
     * Incrementa garantindo TTL: sem o add() prévio o Redis criaria a chave
     * sem expiração e falhas antigas contariam como consecutivas para sempre.
     */
    private function bumpFailures(): int
    {
        Cache::add(self::KEY_FAILURES, 0, now()->addMinutes(self::FAILURE_WINDOW_MINUTES));

        return (int) Cache::increment(self::KEY_FAILURES);
    }
}
