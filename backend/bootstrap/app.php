<?php

declare(strict_types=1);

use App\Domains\Accounts\Middleware\EnsureEntitlement;
use App\Domains\Alerts\Console\EvaluateAlerts;
use App\Domains\Identity\Middleware\EnsureAccountActive;
use App\Domains\Projects\Middleware\ResolveProject;
use App\Domains\Reports\Console\DispatchScheduledReports;
use App\Domains\Reports\Console\InvalidateLegacyExportsCommand;
use App\Domains\Reports\Console\RegenerateDemoExportsCommand;
use App\Domains\Reports\Console\ReportsHealthCommand;
use App\Domains\Requests\Console\EvaluateSla;
use App\Domains\Requests\Console\PruneUploadSessions;
use App\Domains\Tenancy\Middleware\EnsurePlatformAdmin;
use App\Domains\Tenancy\Middleware\EnsurePortal;
use App\Domains\Tenancy\Middleware\ResolveMembership;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ConditionalThrottle;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
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
    ->withCommands([
        InvalidateLegacyExportsCommand::class,
        RegenerateDemoExportsCommand::class,
        ReportsHealthCommand::class,
        DispatchScheduledReports::class,
        PruneUploadSessions::class,
        EvaluateSla::class,
        EvaluateAlerts::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie authentication for the decoupled React frontend.
        $middleware->statefulApi();

        // Every API request gets a correlation-friendly request id.
        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        // Suspended/disabled accounts are denied on EVERY authenticated API request (guests pass through).
        $middleware->api(append: [
            EnsureAccountActive::class,
        ]);

        // Route-middleware aliases. `throttle` is overridden so rate limiting is enforced in production but
        // relaxed in local/dev (single-IP dev + E2E traffic must not trip per-IP limits).
        $middleware->alias([
            // ADR 0002: scope comes from the active MEMBERSHIP, not from users.tenant_id. The old
            // alias still resolves to the new middleware so every existing route keeps working.
            'tenant' => ResolveMembership::class,
            'membership' => ResolveMembership::class,
            'portal' => EnsurePortal::class,
            // NOT an alias of `portal`: the platform owner belongs to no tenant, so they hold no
            // membership to gate on. See EnsurePlatformAdmin.
            'platform' => EnsurePlatformAdmin::class,
            'project' => ResolveProject::class,
            'entitlement' => EnsureEntitlement::class,
            'throttle' => ConditionalThrottle::class,
        ]);

        // Ensure tenant (then project) is resolved BEFORE route-model binding, so the global scopes
        // are active when bound models are fetched (otherwise binding fails closed → 404).
        $middleware->priority([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            Authenticate::class,
            AuthenticateSession::class,
            ResolveMembership::class,
            EnsurePortal::class,
            ResolveProject::class,
            SubstituteBindings::class,
            Authorize::class,
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
