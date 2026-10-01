<?php

namespace App\Exceptions;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

class IntegrationExceptionHandler
{
    /**
     * Intercepta exceções antes do log padrão do Laravel.
     * Retornar false impede o stack trace gigante do framework.
     */
    public function __invoke(Throwable $e): ?bool
    {
        if ($e instanceof MaxAttemptsExceededException)
        {
            Log::error('JOB_MAX_ATTEMPTS_EXCEEDED', [
                'reason' => 'O Job foi descartado permanentemente após esgotar todas as tentativas configuradas.',
                'job'    => $e->getMessage(),
            ]);

            return false;
        }

        return null;
    }
}
