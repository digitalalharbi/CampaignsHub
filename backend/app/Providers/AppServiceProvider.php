<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Audit\Listeners\RecordAuthAudit;
use App\Domains\CRM\Models\Company;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Models\Opportunity;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared per-request tenant context — the authority on "current tenant".
        /*
         * Scope contexts are SCOPED, not singletons: every service inside one request shares the
         * same instance, and the container drops it when the request ends (Octane forgets scoped
         * instances between requests; ResolveMembership::terminate() does the same everywhere else).
         *
         * A singleton would outlive the request that populated it, so the next request handled by
         * the same process could inherit a tenant nobody granted it.
         */
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(MembershipContext::class);

        // Shared per-request project context — the authority on "current project".
        $this->app->singleton(ProjectContext::class);

        // Advertising connector registry; Sandbox is excluded in production.
        $this->app->singleton(
            AdvertisingConnectorRegistry::class,
            fn () => new AdvertisingConnectorRegistry(
                includeSandbox: ! $this->app->environment('production'),
            ),
        );
    }

    public function boot(): void
    {
        // Stable, short morph aliases stored in polymorphic columns (non-enforcing, so framework
        // morphs like Sanctum's tokenable keep working).
        Relation::morphMap([
            'lead' => Lead::class,
            'opportunity' => Opportunity::class,
            'company' => Company::class,
        ]);

        // Audit authentication lifecycle events.
        Event::listen(Login::class, [RecordAuthAudit::class, 'handleLogin']);
        Event::listen(Logout::class, [RecordAuthAudit::class, 'handleLogout']);

        // Login rate limiter. Production stays strict (6/min/IP, env-overridable); local & CI get
        // headroom so automated suites that log in repeatedly (Playwright --repeat-each, seeded roles)
        // don't trip the throttle. The production security control is unchanged.
        RateLimiter::for('auth-login', function (Request $request): Limit {
            $perMinute = $this->app->environment('production')
                ? (int) config('auth.login_throttle', 6)
                : (int) config('auth.login_throttle_local', 60);

            return Limit::perMinute($perMinute)->by((string) $request->ip());
        });

        // Public request intake — strict in production, relaxed for local/CI so repeated E2E runs don't 429.
        RateLimiter::for('requests-intake', function (Request $request): Limit {
            $perMinute = $this->app->environment('production')
                ? (int) config('requests.intake_throttle_per_minute', 6)
                : 120;

            return Limit::perMinute($perMinute)->by((string) $request->ip());
        });

        // OTP / contact-verification requests: strict in production (per destination + IP), relaxed locally.
        RateLimiter::for('otp-request', function (Request $request): Limit {
            $perMinute = $this->app->environment('production') ? 4 : 60;

            return Limit::perMinute($perMinute)->by($request->input('destination', '').'|'.$request->ip());
        });

        // OTP code checks: keyed by IP. Kept modest in production, but generous off-prod — a shared office/
        // household IP (and the E2E suite) can legitimately verify many codes per minute.
        RateLimiter::for('otp-check', function (Request $request): Limit {
            $perMinute = $this->app->environment('production') ? 30 : 600;

            return Limit::perMinute($perMinute)->by((string) $request->ip());
        });
    }
}
