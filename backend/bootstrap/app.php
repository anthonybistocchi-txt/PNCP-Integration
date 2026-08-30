<?php

use App\Exceptions\IntegrationExceptionHandler;
use App\Exceptions\Renderer\ApiExceptionRenderer;
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

        // Delega todo o rendering HTTP para o renderer dedicado
        $exceptions->render(
            fn(\Throwable $e, Request $request) =>
                app(ApiExceptionRenderer::class)->render($e, $request)
        );

        $exceptions->reportable(new IntegrationExceptionHandler());
    })->create();
