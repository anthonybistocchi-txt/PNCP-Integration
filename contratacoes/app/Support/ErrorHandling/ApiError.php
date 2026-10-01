<?php

namespace App\Support\ErrorHandling;

use Illuminate\Http\JsonResponse;

readonly class ApiError
{
    public function __construct(
        public string $error,
        public string $message,
        public array  $details,
        public int    $httpStatus,
    ) {}

    public static function notFound(string $message): self
    {
        return new self(
            error:      'resource_not_found',
            message:    $message,
            details:    [],
            httpStatus: 404,
        );
    }

    public static function conflict(string $message): self
    {
        return new self(
            error:      'resource_conflict',
            message:    $message,
            details:    [],
            httpStatus: 409,
        );
    }

    public static function businessRule(string $message): self
    {
        return new self(
            error:      'business_rule_violation',
            message:    $message,
            details:    [],
            httpStatus: 422,
        );
    }

    public static function validation(array $errors): self
    {
        return new self(
            error:      'validation_error',
            message:    'Os dados enviados são inválidos.',
            details:    $errors,
            httpStatus: 422,
        );
    }

    public static function authenticationRequired(string $message = 'Autenticação necessária para acessar este recurso.'): self
    {
        return new self(
            error:      'authentication_required',
            message:    $message,
            details:    [],
            httpStatus: 401,
        );
    }

    public static function invalidApiKey(string $message = 'API KEY inválida.'): self
    {
        return new self(
            error:      'invalid_api_key',
            message:    $message,
            details:    [],
            httpStatus: 401,
        );
    }

    public static function forbidden(string $message = 'Você não tem permissão para acessar este recurso.'): self
    {
        return new self(
            error:      'forbidden',
            message:    $message,
            details:    [],
            httpStatus: 403,
        );
    }

    public static function rateLimitExceeded(string $message = 'Muitas requisições.'): self
    {
        return new self(
            error:      'rate_limit_exceeded',
            message:    $message,
            details:    [],
            httpStatus: 429,
        );
    }

    public static function serviceUnavailable(string $message = 'Serviço indisponível. Tente novamente mais tarde.'): self
    {
        return new self(
            error:      'service_unavailable',
            message:    $message,
            details:    [],
            httpStatus: 503,
        );
    }

    public static function internalError(string $message = 'Ocorreu um erro inesperado. Tente novamente mais tarde.'): self
    {
        return new self(
            error:      'internal_server_error',
            message:    $message,
            details:    [],
            httpStatus: 500,
        );
    }
}
