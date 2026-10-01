<?php

namespace App\Support\ErrorHandling;

use App\Exceptions\Domain\BusinessRuleException;
use App\Exceptions\Domain\ResourceConflictException;
use App\Exceptions\Domain\ResourceNotFoundException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use PDOException;

class ExceptionClassifier
{
    public function classify(Throwable $e): ApiError
    {
        return match (true)
        {
            $e instanceof ResourceNotFoundException                      => ApiError::notFound($e->getMessage()),
            $e instanceof ResourceConflictException                      => ApiError::conflict($e->getMessage()),
            $e instanceof BusinessRuleException                          => ApiError::businessRule($e->getMessage()),
            $e instanceof UniqueConstraintViolationException             => ApiError::conflict('Recurso já existe.'),
            $e instanceof PDOException && $this->isConnectionError($e)   => ApiError::serviceUnavailable(),
            $e instanceof QueryException && $this->isConnectionError($e) => ApiError::serviceUnavailable(),
            $e instanceof ValidationException                            => ApiError::validation($e->errors()),
            $e instanceof AuthenticationException                        => ApiError::authenticationRequired(),
            $e instanceof ThrottleRequestsException                      => ApiError::rateLimitExceeded(),
            $e instanceof HttpExceptionInterface                         => $this->fromHttpException($e),
            default                                                      => ApiError::internalError(),
        };
    }

    private function isConnectionError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'connection refused') ||
               str_contains($message, 'server has gone away') ||
               str_contains($message, 'could not connect to server');
    }

    private function fromHttpException(HttpExceptionInterface $e): ApiError
    {
        $statusCode = $e->getStatusCode();

        return match ($statusCode)
        {
            401     => ApiError::authenticationRequired($e->getMessage() ?: 'Autenticação necessária para acessar este recurso.'),
            403     => ApiError::forbidden($e->getMessage() ?: 'Você não tem permissão para acessar este recurso.'),
            404     => ApiError::notFound($e->getMessage() ?: 'Recurso não encontrado.'),
            429     => ApiError::rateLimitExceeded($e->getMessage() ?: 'Muitas requisições.'),
            default => new ApiError(
                error:      'http_error_' . $statusCode,
                message:    $e->getMessage() ?: 'Erro HTTP ' . $statusCode,
                details:    [],
                httpStatus: $statusCode
            )
        };
    }
}
