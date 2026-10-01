<?php

namespace App\Support\ErrorHandling;

use App\Exceptions\Infrastructure\PncpRateLimitedException;
use App\Exceptions\Infrastructure\PncpUnavailableException;
use App\Integrations\PNCP\PncpCircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ponto único de decisão sobre esperar ou seguir, antes e depois de falar com
 * o PNCP.
 *
 * Falha de infraestrutura do PNCP vira pausa global + reagendamento (release).
 * Qualquer outra coisa (deadlock, bug, 4xx) continua indo para a
 * JobExceptionPolicy, que já sabe distinguir transitório de permanente.
 */
class PncpFailureHandler
{
    public function __construct(
        private PncpCircuitBreaker $breaker,
        private JobExceptionPolicy $policy,
    ) {}

    /**
     * Guarda executada antes de qualquer requisição.
     *
     * @return bool true quando o job foi devolvido para a fila e deve retornar.
     */
    public function shouldWait(object $job, string $label, string $scope): bool
    {
        if ($this->breaker->isOpen($scope))
        {
            $delay = $this->breaker->remainingSeconds($scope) + $this->jitter('resume');
            $job->release($delay);

            return true;
        }

        // Pausa vencida: apenas um job sonda a API. Se outro já pegou o lock,
        // este espera o resultado em vez de repetir a enxurrada.
        if ($this->breaker->isHalfOpen($scope) && ! $this->breaker->acquireProbe($scope))
        {
            $delay = $this->jitter('contend');
            $job->release($delay);

            return true;
        }

        return false;
    }

    /**
     * @return bool true quando o job foi devolvido para a fila e deve retornar.
     */
    public function handle(Throwable $error, object $job, string $scope, array $context = []): bool
    {
        if ($error instanceof PncpRateLimitedException)
        {
            $this->breaker->recordRateLimit($scope, $error->retryAfter);

            Log::warning('PNCP_RATE_LIMITED', array_merge($context, [
                'scope'            => $scope,
                'retry_after'      => $error->retryAfter,
                'breaker_open_for' => $this->breaker->remainingSeconds($scope),
            ]));

            $job->release($this->resumeDelay($scope));

            return true;
        }

        if ($error instanceof PncpUnavailableException || $error instanceof ConnectionException)
        {
            $opened = $this->breaker->recordServerFailure($scope);

            Log::warning('PNCP_UPSTREAM_UNAVAILABLE', array_merge($context, [
                'scope'                => $scope,
                'exception'            => get_class($error),
                'message'              => $error->getMessage(),
                'breaker_opened'       => $opened,
                'breaker_open_for'     => $this->breaker->remainingSeconds($scope),
                'consecutive_failures' => $this->breaker->consecutiveFailures($scope),
            ]));

            $job->release($this->resumeDelay($scope));

            return true;
        }

        $this->policy->handle($error, $job, $context);

        return false;
    }

    /**
     * Com o breaker aberto, espera a pausa inteira. Sem ele (falha isolada
     * abaixo do limiar), espera pouco e tenta de novo.
     */
    private function resumeDelay(string $scope): int
    {
        return $this->breaker->isOpen($scope)
            ? $this->breaker->remainingSeconds($scope) + $this->jitter('resume')
            : $this->jitter('contend');
    }

    /**
     * O jitter é o que impede as dezenas de páginas represadas de voltarem
     * todas no mesmo segundo assim que a pausa vence.
     */
    private function jitter(string $profile): int
    {
        return random_int(
            (int) config("pncp.jitter.{$profile}_min", $profile === 'resume' ? 5 : 30),
            (int) config("pncp.jitter.{$profile}_max", $profile === 'resume' ? 45 : 90)
        );
    }
}
