<?php

namespace App\Exceptions\Renderer;

use App\Support\ErrorHandling\ExceptionClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (!($request->is('api/*') || $request->expectsJson())) {
            return null;
        }

        $apiError = app(ExceptionClassifier::class)->classify($e);
        $requestId = $request->header('X-Request-ID', 'unknown');

        $payload = [
            'error'      => $apiError->error,
            'message'    => $apiError->message,
            'details'    => (object) $apiError->details,
            'request_id' => $requestId,
        ];

        if (app()->hasDebugModeEnabled() && !app()->environment('production')) {
            $payload['debug'] = [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ];
        }

        return response()->json($payload, $apiError->httpStatus);
    }
}
