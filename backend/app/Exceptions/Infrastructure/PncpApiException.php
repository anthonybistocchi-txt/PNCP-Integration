<?php

namespace App\Exceptions\Infrastructure;

use Exception;
use Illuminate\Http\Client\Response;

class PncpApiException extends Exception
{
    public function __construct(
        string $message,
        public int $statusCode = 0,
        public ?Response $response = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
