<?php

namespace App\Support\ErrorHandling;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

class JobExceptionPolicy
{
    public function handle(Throwable $e, object $job, array $context = []): void
    {
        if ($this->isTransient($e))
        {
            Log::warning('JOB_TRANSIENT_ERROR', array_merge($context, [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]));

            // Re-throw so Laravel's queue worker handles the retry (using backoff)
            throw $e;
        }

        if ($this->isPermanent($e))
        {
            Log::error('JOB_PERMANENT_ERROR', array_merge($context, [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]));

            $job->fail($e);
            return;
        }

        // Se não é nem transitório nem permanente explícito, consideramos crítico
        Log::critical('JOB_CRITICAL_ERROR', array_merge($context, [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
        ]));

        $job->fail($e);
    }

    private function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException)
        {
            return true;
        }

        if ($e instanceof RequestException)
        {
            $status = $e->response->status();
            return in_array($status, [429, 500, 502, 503, 504]);
        }

        if ($e instanceof QueryException && $this->isDeadlock($e))
        {
            return true;
        }

        if ($e instanceof PDOException && $this->isConnectionError($e))
        {
            return true;
        }

        return false;
    }

    private function isPermanent(Throwable $e): bool
    {
        if ($e instanceof RequestException)
        {
            $status = $e->response->status();
            return in_array($status, [400, 401, 403, 404]);
        }

        return false;
    }

    private function isDeadlock(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'deadlock') || str_contains($message, '40p01') || str_contains($message, '40001');
    }

    private function isConnectionError(PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'connection refused') ||
               str_contains($message, 'server has gone away') ||
               str_contains($message, 'could not connect to server');
    }
}
