<?php

declare(strict_types=1);

use App\Domains\Accounts\Middleware\EnsureEntitlement;
use App\Domains\Alerts\Console\EvaluateAlerts;
use App\Domains\Identity\Middleware\EnsureAccountActive;
use App\Domains\Integrations\Console\PruneRawPayloadsCommand;
use App\Domains\Integrations\Console\RefreshAdPlatformTokensCommand;
use App\Domains\Integrations\Console\SyncAdPlatformsCommand;
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
use App\Http\Middleware\SetLocale;
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
        // INTEG-SYNC-001 — the sweep that drives synced data, the token refresh that runs ahead of
        // need, and the retention that keeps raw payloads from becoming the largest table here.
        SyncAdPlatformsCommand::class,
        RefreshAdPlatformTokensCommand::class,
        PruneRawPayloadsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie authentication for the decoupled React frontend.
        $middleware->statefulApi();

        /*
         * The sandbox gateway's confirm form (PAY-SANDBOX-001).
         *
         * It is an ordinary HTML form served by this application, so Sanctum's stateful handling
         * applies CSRF to it — and a gateway's page has no CSRF token from our SPA, which is exactly
         * the point: a real gateway posts from its own origin too. Excluding it changes no security
         * property, because the endpoint grants nothing. It builds a SIGNED event and hands it to the
         * webhook, which verifies the signature before anything moves; a forged post reaches a
         * verification it cannot pass. The route only exists outside production.
         */
        /*
         * WEBHOOK-001 — a provider's server has no CSRF token and never will.
         *
         * Excluding these changes no security property for the same reason as above: the endpoint
         * grants nothing and verifies an HMAC over the raw body before a single row is written. A
         * forged POST reaches a signature check it cannot pass and is answered 401 with nothing
         * stored. CSRF protects a BROWSER session from being used by another origin; there is no
         * session here to protect.
         */
        $middleware->validateCsrfTokens(except: [
            'api/v1/payments/sandbox/*',
            'api/v1/webhooks/*',
        ]);

        /*
         * An unauthenticated API call is a 401, not a redirect (LOGIN-003).
         *
         * Laravel's default is to send a guest to a named `login` route. This application has no such
         * route — it is an API with an SPA in front of it — so the redirect threw
         * RouteNotFoundException, and the caller got a 500 with a stack trace instead of "you are not
         * signed in". Only visible without an `Accept: application/json` header, which the SPA always
         * sends and a curl or a mobile client easily does not.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');

        /*
         * Every API request gets a correlation-friendly request id, and a language (I18N-001).
         *
         * `SetLocale` is prepended so it is in force before ANY other middleware can answer — the
         * rate limiter, the auth gate and the account-suspension guard all produce customer-facing
         * messages, and each of them refuses before a controller is ever reached.
         */
        $middleware->api(prepend: [
            AssignRequestId::class,
            SetLocale::class,
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

            /*
             * The messages are translated (I18N-001).
             *
             * This renderer is the single point every JSON error in the application passes through,
             * so translating it here is what makes an expired session or a rate limit readable to an
             * Arabic customer — the alternative was to catch each of them at hundreds of call sites.
             *
             * `SetLocale` runs as API middleware, which does NOT cover a failure raised before the
             * pipeline (a malformed route, a 404 on a path no middleware group owns), so the locale
             * is resolved here too rather than assumed. It is idempotent.
             */
            app()->setLocale(SetLocale::resolve($request));

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    __('api.validation'),
                    $e->errors(),
                    status: 422,
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    __('api.unauthenticated'), status: 401,
                ),
                $e instanceof AuthorizationException => ApiResponse::error(
                    __('api.unauthorized'), status: 403,
                ),
                /*
                 * 419, matched on the STATUS rather than on the exception class.
                 *
                 * `$e instanceof TokenMismatchException` never fired: Laravel's own
                 * `Handler::prepareException()` converts that exception into a plain `HttpException`
                 * BEFORE any render callback sees it, keeping the framework's English message and
                 * passing it through the `HttpExceptionInterface` branch below. So the branch was
                 * dead code and an Arabic customer whose tab had been left open was told «CSRF token
                 * mismatch.» — caught only by asking the running server what it actually answers.
                 */
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => ApiResponse::error(
                    __('api.csrf'), status: 419,
                ),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    __('api.not_found'), status: 404,
                ),
                $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                    __('api.too_many_requests'), status: 429,
                ),
                /*
                 * An `abort(403, '…')` carries a message written at the call site, and that message
                 * is already in the language it was written in. Passing it through a translator
                 * would render the sentence itself as a missing key, so it is used as-is and only
                 * the fallback — an abort with no message at all — is translated.
                 */
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() ?: __('api.failed'),
                    status: $e->getStatusCode(),
                ),
                default => ApiResponse::error(
                    app()->hasDebugModeEnabled() ? $e->getMessage() : __('api.server_error'),
                    app()->hasDebugModeEnabled() ? ['exception' => [class_basename($e)]] : null,
                    status: 500,
                ),
            };
        });
    })->create();
