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
 *
 * O estado é isolado por escopo (`listagem` vs. `documentos`): os dois
 * endpoints do PNCP têm perfis de instabilidade diferentes na prática (a
 * listagem se recupera em minutos, os documentos podem ficar horas fora do
 * ar), e sem essa separação uma degradação isolada de um lado trava o outro.
 */
class PncpCircuitBreaker
{
    public const SCOPE_LISTAGEM   = 'listagem';
    public const SCOPE_DOCUMENTOS = 'documentos';

    private const KEY_OPEN_UNTIL        = 'open_until';
    private const KEY_FAILURES          = 'failures';
    private const KEY_LEVEL             = 'level';
    private const KEY_PROBE             = 'probe';
    private const KEY_PROLONGED_OUTAGES = 'prolonged_outages';

    /** Janela em que falhas ainda contam como "consecutivas". */
    private const FAILURE_WINDOW_MINUTES = 10;

    /** Por quanto tempo lembramos do degrau de escalonamento. */
    private const LEVEL_MEMORY_MINUTES = 60;

    public function isOpen(string $scope): bool
    {
        return $this->remainingSeconds($scope) > 0;
    }

    public function remainingSeconds(string $scope): int
    {
        return max(0, ((int) Cache::get($this->key(self::KEY_OPEN_UNTIL, $scope), 0)) - now()->timestamp);
    }

    /**
     * Pausa vencida, mas ainda sem nenhum sucesso confirmando que a API voltou.
     */
    public function isHalfOpen(string $scope): bool
    {
        return ! $this->isOpen($scope) && $this->level($scope) > 0;
    }

    /**
     * Só o primeiro job a chamar consegue sondar a API. Os demais devem
     * aguardar, para não repetir a enxurrada assim que a pausa vence.
     */
    public function acquireProbe(string $scope): bool
    {
        return Cache::lock($this->key(self::KEY_PROBE, $scope), (int) config('pncp.breaker.probe_lock_seconds'))->get();
    }

    public function consecutiveFailures(string $scope): int
    {
        return (int) Cache::get($this->key(self::KEY_FAILURES, $scope), 0);
    }

    /**
     * Registra 5xx ou timeout. Abre a pausa apenas quando as falhas
     * consecutivas atingem o limiar, ou quando a sondagem do meio aberto
     * falhou (aí não faz sentido esperar o limiar de novo).
     */
    public function recordServerFailure(string $scope): bool
    {
        $wasHalfOpen = $this->isHalfOpen($scope);
        $failures    = $this->bumpFailures($scope);
        $threshold   = max(1, (int) config('pncp.breaker.server_error_threshold'));

        if ($failures < $threshold && ! $wasHalfOpen) {
            return false;
        }

        $this->open(
            scope: $scope,
            seconds: $this->nextBackoffSeconds($scope, 'pncp.breaker.server_error_backoff'),
            reason: $wasHalfOpen ? 'sondagem_falhou' : 'falhas_consecutivas',
            failures: $failures,
            wasHalfOpen: $wasHalfOpen
        );

        return true;
    }

    /**
     * Registra 429. Abre imediatamente: é a própria API dizendo o quanto
     * deveríamos esperar.
     */
    public function recordRateLimit(string $scope, ?int $retryAfter = null): bool
    {
        $wasHalfOpen = $this->isHalfOpen($scope);
        $failures    = $this->bumpFailures($scope);

        // O degrau avança sempre, mesmo quando a duração vem do header: é ele
        // que marca o meio aberto e faz a próxima pausa ser mais longa.
        $escalonado = $this->nextBackoffSeconds($scope, 'pncp.breaker.rate_limit_backoff');

        $this->open(
            scope: $scope,
            seconds: $retryAfter !== null && $retryAfter > 0 ? $retryAfter : $escalonado,
            reason: $retryAfter !== null ? 'retry_after_header' : 'rate_limit',
            failures: $failures,
            wasHalfOpen: $wasHalfOpen
        );

        return true;
    }

    /**
     * A API respondeu. Zera contador, escalonamento e pausa.
     */
    public function recordSuccess(string $scope): void
    {
        if ($this->isHalfOpen($scope)) {
            Log::info('PNCP_BREAKER_CLOSED', [
                'scope'  => $scope,
                'reason' => 'Sondagem bem-sucedida, requisições liberadas.',
            ]);
        }

        Cache::forget($this->key(self::KEY_FAILURES, $scope));
        Cache::forget($this->key(self::KEY_LEVEL, $scope));
        Cache::forget($this->key(self::KEY_OPEN_UNTIL, $scope));
        Cache::forget($this->key(self::KEY_PROLONGED_OUTAGES, $scope));
        Cache::lock($this->key(self::KEY_PROBE, $scope))->forceRelease();
    }

    /**
     * Abre a pausa por um número fixo de minutos, sem escalonar.
     */
    public function trip(string $scope, int $minutes = 5): void
    {
        $this->openFor($scope, max(1, $minutes) * 60);
    }

    private function open(string $scope, int $seconds, string $reason, int $failures, bool $wasHalfOpen): void
    {
        // Válvula de escape: se a sondagem do meio aberto já falhou repetidas
        // vezes seguidas, a degradação é real e prolongada (não um blip). Em
        // 05/09 o breaker ficou preso no nível máximo por ~6h porque cada
        // sondagem falha renovava o TTL do nível a cada 20 min, sem nenhum
        // sinal de que aquilo já era um incidente. Aqui a pausa passa a ser
        // bem maior e o incidente é logado como crítico.
        $prolongedOutages = $wasHalfOpen ? $this->bumpProlongedOutages($scope) : $this->resetProlongedOutages($scope);
        $threshold        = max(1, (int) config('pncp.breaker.prolonged_outage_threshold'));

        if ($wasHalfOpen && $prolongedOutages >= $threshold) {
            $seconds = max($seconds, (int) config('pncp.breaker.prolonged_outage_backoff_minutes') * 60);

            Log::critical('PNCP_PROLONGED_OUTAGE', [
                'scope'              => $scope,
                'reason'             => $reason,
                'seconds'            => $seconds,
                'level'              => $this->level($scope),
                'consecutive_failures' => $failures,
                'sondagens_falhas_seguidas' => $prolongedOutages,
                'message'            => "PNCP ({$scope}) segue indisponível após {$prolongedOutages} sondagens seguidas. Pausa estendida para reduzir ruído.",
            ]);
        }

        $this->openFor($scope, $seconds);

        Log::warning('PNCP_BREAKER_OPENED', [
            'scope'               => $scope,
            'reason'              => $reason,
            'seconds'             => $seconds,
            'level'               => $this->level($scope),
            'consecutive_failures'=> $failures,
        ]);
    }

    private function openFor(string $scope, int $seconds): void
    {
        $seconds = max(1, $seconds);

        // A folga no TTL evita uma janela em que a chave expira antes do
        // horário que ela mesma anuncia.
        Cache::put(
            $this->key(self::KEY_OPEN_UNTIL, $scope),
            now()->timestamp + $seconds,
            now()->addSeconds($seconds + 60)
        );

        Cache::forget($this->key(self::KEY_FAILURES, $scope));

        // Libera o lock para que a próxima janela de meio aberto tenha uma
        // sondagem nova, mesmo que a pausa seja mais curta que o TTL do lock.
        Cache::lock($this->key(self::KEY_PROBE, $scope))->forceRelease();
    }

    /**
     * Avança um degrau na escala e devolve a pausa correspondente.
     */
    private function nextBackoffSeconds(string $scope, string $configKey): int
    {
        $scale = (array) config($configKey);

        if ($scale === []) {
            $scale = [(int) config('pncp.circuit_breaker_minutes', 2)];
        }

        $level = min($this->level($scope) + 1, count($scale));

        Cache::put($this->key(self::KEY_LEVEL, $scope), $level, now()->addMinutes(self::LEVEL_MEMORY_MINUTES));

        return (int) $scale[$level - 1] * 60;
    }

    private function level(string $scope): int
    {
        return (int) Cache::get($this->key(self::KEY_LEVEL, $scope), 0);
    }

    /**
     * Incrementa garantindo TTL: sem o add() prévio o Redis criaria a chave
     * sem expiração e falhas antigas contariam como consecutivas para sempre.
     */
    private function bumpFailures(string $scope): int
    {
        $key = $this->key(self::KEY_FAILURES, $scope);

        Cache::add($key, 0, now()->addMinutes(self::FAILURE_WINDOW_MINUTES));

        return (int) Cache::increment($key);
    }

    /**
     * Conta quantas sondagens do meio aberto falharam em sequência, sem
     * nenhum sucesso entre elas. Zerado por recordSuccess() ou quando uma
     * abertura não vem de uma sondagem (falhas_consecutivas normais).
     */
    private function bumpProlongedOutages(string $scope): int
    {
        $key = $this->key(self::KEY_PROLONGED_OUTAGES, $scope);

        Cache::add($key, 0, now()->addMinutes(self::LEVEL_MEMORY_MINUTES));

        return (int) Cache::increment($key);
    }

    private function resetProlongedOutages(string $scope): int
    {
        Cache::forget($this->key(self::KEY_PROLONGED_OUTAGES, $scope));

        return 0;
    }

    private function key(string $suffix, string $scope): string
    {
        return "pncp_cb:{$scope}:{$suffix}";
    }
}
