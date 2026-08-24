<?php

use App\Http\Middleware\AddRequestIdMiddleware;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\VerifyPermissionToCreateUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Aplica o AddRequestIdMiddleware em todas as requisições da API.
        // Deve ser o primeiro a rodar para que o request_id esteja disponível
        // em todos os logs subsequentes (AuthMiddleware, Controller, etc).
        $middleware->prependToGroup('api', AddRequestIdMiddleware::class);

        $middleware->alias([
            'auth.api'                         => AuthMiddleware::class,
            'verify_permission_to_create_user' => VerifyPermissionToCreateUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Garante que qualquer erro não tratado na API retorne JSON, nunca HTML.
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Retorna uma mensagem genérica em produção para não vazar detalhes internos.
        // Em desenvolvimento, a mensagem real do erro é exibida para facilitar debug.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                // Deixa o Laravel tratar exceções HTTP (como 404, 403) e Validação (422) naturalmente
                if ($e instanceof \Illuminate\Validation\ValidationException ||
                    $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ||
                    $e instanceof \Illuminate\Auth\AuthenticationException) {
                    return null;
                }

                $statusCode = $e->getCode();
                if (!is_numeric($statusCode) || $statusCode < 400 || $statusCode > 599) {
                    $statusCode = 500;
                }

                $errorTitle = $statusCode >= 500 ? 'Erro interno do servidor.' : 'Erro na requisição.';
                $message = $statusCode >= 500 && app()->environment('production') 
                    ? 'Internal Server Error' 
                    : $e->getMessage();

                return response()->json([
                    'error'   => $errorTitle,
                    'message' => $message,
                ], $statusCode);
            }
        });

        $exceptions->reportable(new \App\Exceptions\IntegrationExceptionHandler());
    })->create();
