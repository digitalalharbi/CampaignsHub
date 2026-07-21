<?php

declare(strict_types=1);

use App\Domains\Tenancy\Middleware\ResolveTenant;
use App\Http\Middleware\AssignRequestId;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie authentication for the decoupled React frontend.
        $middleware->statefulApi();

        // Every API request gets a correlation-friendly request id.
        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        // Route-middleware aliases.
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'project' => \App\Domains\Projects\Middleware\ResolveProject::class,
        ]);

        // Ensure tenant (then project) is resolved BEFORE route-model binding, so the global scopes
        // are active when bound models are fetched (otherwise binding fails closed → 404).
        $middleware->priority([
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            ResolveTenant::class,
            \App\Domains\Projects\Middleware\ResolveProject::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render all API (JSON) exceptions through the standard envelope.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // fall back to default rendering for web
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    'The submitted data is invalid.',
                    $e->errors(),
                    status: 422,
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    'Unauthenticated.', status: 401,
                ),
                $e instanceof AuthorizationException => ApiResponse::error(
                    'This action is unauthorized.', status: 403,
                ),
                $e instanceof TokenMismatchException => ApiResponse::error(
                    'CSRF token mismatch.', status: 419,
                ),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    'The requested resource was not found.', status: 404,
                ),
                $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                    'Too many requests.', status: 429,
                ),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() ?: 'Request failed.',
                    status: $e->getStatusCode(),
                ),
                default => ApiResponse::error(
                    app()->hasDebugModeEnabled() ? $e->getMessage() : 'Internal server error.',
                    app()->hasDebugModeEnabled() ? ['exception' => [class_basename($e)]] : null,
                    status: 500,
                ),
            };
        });
    })->create();
