<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Accounts\Services\AccountGrants;
use App\Domains\Audit\Listeners\RecordAuthAudit;
use App\Domains\CRM\Models\Company;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Models\Opportunity;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Metrics\Contracts\CurrencyRateSource;
use App\Domains\Metrics\Rates\CurrencyRateFeed;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Observers\SubscriptionAuditObserver;
use App\Domains\Subscriptions\Observers\SubscriptionPaymentAuditObserver;
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

        /*
         * Administrative grants, memoised for the length of ONE request (GRANT-001).
         *
         * `AccountEntitlements` asks it a question per menu item, so a fresh instance per resolution
         * would read the same rows a dozen times to render one rail. Scoped rather than singleton for
         * the reason above: a permission cache that outlived its request would answer the next one
         * with the previous tenant's exceptions.
         */
        $this->app->scoped(AccountGrants::class);

        /*
         * The platform operator's provider configuration (PROVCFG-001).
         *
         * Scoped rather than singleton for the same reason as the contexts above — it memoises rows
         * from `provider_configurations`, and a singleton would keep answering with the keys that were
         * in place before an operator rotated one, for the life of the worker process.
         */
        $this->app->scoped(ProviderConfigurationService::class);

        // Advertising connector registry; Sandbox is excluded in production.
        $this->app->singleton(
            AdvertisingConnectorRegistry::class,
            fn () => new AdvertisingConnectorRegistry(
                includeSandbox: ! $this->app->environment('production'),
            ),
        );

        /*
         * FX-FEED-001 — the published rate source, if this deployment has chosen one.
         *
         * Resolved from `config('fx.rates.driver')` and NULL by default, because no publisher is
         * chosen in this repository: which source a deployment trusts is a commercial decision, and
         * a default here would make it silently on the operator's behalf. A class that is named but
         * cannot be resolved binds as null too — a misconfigured driver must read as «the feed is not
         * usable», which is what the /admin surface says, rather than crash every request that
         * touches money.
         */
        $this->app->scoped(CurrencyRateFeed::class, function (): CurrencyRateFeed {
            $driver = config('fx.rates.driver');
            $source = null;

            if (is_string($driver) && $driver !== '' && class_exists($driver)) {
                $resolved = $this->app->make($driver);
                $source = $resolved instanceof CurrencyRateSource ? $resolved : null;
            }

            return new CurrencyRateFeed($source);
        });
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

        /*
         * OPS-002 — money and entitlement changes are audited at the MODEL, not at the call site.
         *
         * The subscription lifecycle mutates from about ten places, most of them unattended on a
         * schedule, and payments are written by webhook handlers and adapters. An audit line per call
         * site is one someone eventually forgets to add; an observer cannot be forgotten. See
         * `SubscriptionAuditObserver` for which columns are considered material and why.
         */
        Subscription::observe(SubscriptionAuditObserver::class);
        SubscriptionPayment::observe(SubscriptionPaymentAuditObserver::class);

        /*
         * Login rate limiter. Production stays strict (6/min/IP, env-overridable).
         *
         * Off-production the allowance is far larger, because the acceptance suite signs in six
         * seeded roles at the start of EVERY run and those runs come back to back — a spot-check on
         * one browser, then the full three-browser gate, is easily sixty logins inside a rolling
         * minute from a single address. At 60 the sixty-first came back 429, the storage-state setup
         * failed, and the whole gate reported `419 did not run` — a rate limit wearing the costume of
         * a broken sign-in page.
         *
         * `otp-check` already carries an allowance of this shape for the same reason. The production
         * control is untouched.
         */
        RateLimiter::for('auth-login', function (Request $request): Limit {
            $perMinute = $this->app->environment('production')
                ? (int) config('auth.login_throttle', 6)
                : (int) config('auth.login_throttle_local', 600);

            return Limit::perMinute($perMinute)->by((string) $request->ip());
        });

        /*
         * Registration — the last public endpoint still throttled inline (APP-100).
         *
         * `/register` carried a literal `throttle:6,1` while every other public route here had been
         * given an environment-aware limiter. Six a minute is the right production number and stays
         * exactly that; what it could not survive is the acceptance suite, which opens two accounts
         * per browser project from one address and runs three projects back to back. The seventh
         * registration in a rolling minute came back 429, the form stayed on `/register`, and the
         * failure looked like a broken form rather than a rate limit — it appeared on whichever
         * browser happened to run seventh, which is why it read as a Firefox problem.
         *
         * Raising the production limit or retrying the test would both have been wrong: the first
         * weakens a real control, the second hides it.
         */
        RateLimiter::for('registration', function (Request $request): Limit {
            $perMinute = $this->app->environment('production')
                ? (int) config('accounts.registration_throttle', 6)
                : 60;

            return Limit::perMinute($perMinute)->by((string) $request->ip());
        });

        /*
         * Re-issuing a verification challenge — SIGNUP-THROTTLE-001.
         *
         * `/{registration}/resend` carried a literal `throttle:6,1`, and a literal throttle is keyed
         * by the authenticated user or, failing that, by the IP. Every caller of this endpoint is a
         * guest by definition — an applicant has no account yet — so the allowance was six resends a
         * minute SHARED BY EVERYONE BEHIND ONE ADDRESS. An office, a campus, a hotel or any carrier
         * doing CGNAT is one address, and the second colleague to sign up was refused a code they had
         * asked for once.
         *
         * The risk this control exists for belongs to the APPLICATION — do not spam this applicant's
         * phone, do not burn SMS credit on them — and the key never mentioned it. So it is keyed by
         * the application now, and in production the per-applicant number is STRICTER than the six it
         * replaces, because one applicant has no legitimate reason to need more. The address keeps a
         * ceiling of its own so opening applications in a loop is not a way around the first limit.
         *
         * Same defect, same shape, as APP-100 on `/register` above — whose comment calls that «the
         * last public endpoint still throttled inline». It was not; this one is.
         *
         * @return list<Limit>
         */
        RateLimiter::for('registration-resend', function (Request $request): array {
            $production = $this->app->environment('production');

            $perApplication = $production
                ? (int) config('accounts.resend_throttle_per_application', 3)
                : (int) config('accounts.resend_throttle_per_application_local', 60);
            $perAddress = $production
                ? (int) config('accounts.resend_throttle_per_address', 12)
                : (int) config('accounts.resend_throttle_per_address_local', 600);

            // The RAW route value: this runs after model binding, and a model is not a cache key.
            $application = (string) ($request->route()?->originalParameter('registration') ?? 'unknown');

            return [
                Limit::perMinute($perApplication)->by('registration-resend:application:'.$application),
                Limit::perMinute($perAddress)->by('registration-resend:address:'.$request->ip()),
            ];
        });

        /*
         * Data-subject requests — LEGAL-THROTTLE-001, the same defect on the flow it matters most on.
         *
         * `/data-deletion` and `/data-requests` each carried a literal `throttle:5,1`,
         * keyed by IP for the same reason as above: these endpoints exist FOR people with no account.
         * `/data-deletion` is the URL this platform hands to Meta, TikTok, Snapchat and Google as its
         * deletion contact — it is opened by a reviewer, and it is the one right that has to work
         * when the customer has already lost access to everything else. Five a minute per address
         * refused an agency filing for several clients, a household, and two reviewers at one desk.
         *
         * Keyed by the SUBJECT of the request, with the address kept as an abuse ceiling so a loop
         * over invented addresses cannot use this as a mailer.
         *
         * @return list<Limit>
         */
        RateLimiter::for('data-subject-request', function (Request $request): array {
            $production = $this->app->environment('production');

            $perSubject = $production
                ? (int) config('security.data_request_throttle_per_subject', 3)
                : (int) config('security.data_request_throttle_per_subject_local', 60);
            $perAddress = $production
                ? (int) config('security.data_request_throttle_per_address', 12)
                : (int) config('security.data_request_throttle_per_address_local', 600);

            $subject = mb_strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute($perSubject)->by('data-subject-request:subject:'.$subject),
                Limit::perMinute($perAddress)->by('data-subject-request:address:'.$request->ip()),
            ];
        });

        /*
         * The public price list and the public service catalogue.
         *
         * PRODUCTION IS UNCHANGED at 60/min/IP — this is not a loosening of a control. It is the same
         * environment split `auth-login`, `registration`, `requests-intake` and `otp-check` already
         * carry, and for the identical reason: the acceptance suite drives the marketing page from ONE
         * address, so a per-IP budget meant for the open internet ends up measuring the suite instead
         * of the product.
         *
         * It presented as a defect somewhere else entirely. Adding thirty homepage loads
         * (`mobile-first-screen.spec.ts`) pushed the window over 60, and the spec that runs next
         * alphabetically — `platform-control` — read the 429 as `body.data.plans` on null and failed
         * with «Cannot read properties of null». Nothing was wrong with the plan catalogue; the
         * request had simply been refused.
         *
         * These two endpoints are read-only public reads. They send nothing, charge nothing and cost
         * no credit, which is why the off-production allowance can be generous without weakening
         * anything that matters.
         */
        RateLimiter::for('public-catalogue', function (Request $request): Limit {
            $perMinute = $this->app->environment('production')
                ? (int) config('subscriptions.public_catalogue_throttle', 60)
                : 1200;

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
