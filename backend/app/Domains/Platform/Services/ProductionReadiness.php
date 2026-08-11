<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use Illuminate\Support\Facades\Config;

/**
 * PROD-CONFIG-001 — what must be true before this install may take a real customer's money.
 *
 * ## Why a check and not a boot guard
 *
 * Every fact below is already knowable from configuration, and none of them was checked anywhere.
 * An install could boot in production with `APP_DEBUG=true`, a `localhost` callback URL, a Moyasar
 * TEST key and no webhook token, and the only symptom would be customers paying into a gateway
 * whose confirmation could never arrive. Nothing crashes; the money simply goes nowhere the product
 * can see.
 *
 * It is a command rather than a `boot()` throw on purpose. A guard that aborts the application on a
 * misconfiguration turns one wrong environment variable into a total outage — including for the
 * customers already inside, whose sessions had nothing to do with it. The deploy pipeline runs this
 * BEFORE traffic moves, `/admin` reads it, and the failure is loud in the place a person can act on
 * it.
 *
 * ## `fail` versus `warn`
 *
 * `fail` is «this install will mislead somebody or lose their money». `warn` is «this is not
 * finished, and the product already says so honestly» — an unconfigured mail provider is a warning
 * because the product never claims a message was sent without one, which is a decision already
 * taken and tested, not a hole.
 *
 * Everything here is derived from configuration only. **No secret is ever returned** — a check
 * reports the shape of a key (test/live/absent), never its value.
 */
final class ProductionReadiness
{
    /** @var list<array{key: string, level: string, message: string, fix: string}> */
    private array $findings = [];

    /**
     * @return array{ready: bool, environment: string, failures: int, warnings: int, findings: list<array{key: string, level: string, message: string, fix: string}>}
     */
    public function run(): array
    {
        $this->findings = [];

        $env = (string) Config::get('app.env');
        $production = $env === 'production';

        $this->checkApplication($production);
        $this->checkUrls($production);
        $this->checkSession($production);
        $this->checkInfrastructure($production);
        $this->checkPayments($production);
        $this->checkRecurringBilling($production);
        $this->checkMail();
        $this->checkExchangeRates();

        $failures = count(array_filter($this->findings, fn (array $f) => $f['level'] === 'fail'));

        return [
            'ready' => $failures === 0,
            'environment' => $env,
            'failures' => $failures,
            'warnings' => count($this->findings) - $failures,
            'findings' => array_values($this->findings),
        ];
    }

    private function checkApplication(bool $production): void
    {
        if ($production && Config::get('app.debug') === true) {
            $this->fail('app.debug', 'APP_DEBUG is on in production — a stack trace on any error publishes file paths, queries and configuration to whoever triggered it.', 'Set APP_DEBUG=false.');
        }

        if (! is_string(Config::get('app.key')) || Config::get('app.key') === '') {
            $this->fail('app.key', 'APP_KEY is not set — every encrypted value, including stored provider tokens, is unreadable or unwritable.', 'Run php artisan key:generate and keep the value with your secrets.');
        }
    }

    private function checkUrls(bool $production): void
    {
        foreach (['app.url' => 'APP_URL', 'brand.frontend_url' => 'FRONTEND_URL'] as $key => $name) {
            $url = Config::get($key);

            if (! is_string($url) || $url === '') {
                // FRONTEND_URL is optional in a single-origin deployment; APP_URL never is.
                if ($key === 'app.url') {
                    $this->fail($key, "{$name} is not set — callbacks, webhooks and every link in an email are built from it.", "Set {$name} to the public origin, with https.");
                }

                continue;
            }

            $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');

            if ($production && parse_url($url, PHP_URL_SCHEME) !== 'https') {
                $this->fail($key, "{$name} is not https — a payment callback and a session cookie both travel over it.", "Set {$name} to an https origin.");
            }

            if ($production && $this->isLocal($host)) {
                $this->fail($key, "{$name} points at {$host}, which no customer and no gateway can reach.", "Set {$name} to the public hostname.");
            }
        }
    }

    private function checkSession(bool $production): void
    {
        if (! $production) {
            return;
        }

        if (Config::get('session.secure') !== true) {
            $this->fail('session.secure', 'SESSION_SECURE_COOKIE is off — the session cookie may be sent over plain http.', 'Set SESSION_SECURE_COOKIE=true.');
        }

        $domain = Config::get('session.domain');
        $appHost = (string) (parse_url((string) Config::get('app.url'), PHP_URL_HOST) ?? '');

        /*
         * A cookie domain that does not cover the application's own host is the failure that reads
         * as «login does nothing»: the browser accepts the response, stores nothing, and the next
         * request is anonymous. Checked as a suffix so `.campaignshub.io` correctly covers
         * `app.campaignshub.io`.
         */
        if (is_string($domain) && $domain !== '' && $appHost !== '') {
            $bare = ltrim($domain, '.');

            if ($appHost !== $bare && ! str_ends_with($appHost, '.'.$bare)) {
                $this->fail('session.domain', "SESSION_DOMAIN ({$domain}) does not cover APP_URL's host ({$appHost}) — the session cookie will be discarded and every sign-in will appear to do nothing.", 'Set SESSION_DOMAIN to that host, or to a parent domain of it.');
            }
        }
    }

    private function checkInfrastructure(bool $production): void
    {
        if (! $production) {
            return;
        }

        if (Config::get('database.default') !== 'pgsql') {
            $this->fail('database.default', 'The database connection is not PostgreSQL, which is the only engine this schema is migrated and tested against.', 'Set DB_CONNECTION=pgsql.');
        }

        if (Config::get('queue.default') === 'sync') {
            $this->fail('queue.default', 'The queue is sync — report generation, syncs and mail would run inside the web request, and a failed job would have nowhere to be retried from.', 'Set QUEUE_CONNECTION=redis and run a worker (Horizon).');
        }

        if (Config::get('cache.default') === 'array') {
            $this->warn('cache.default', 'The cache is the array driver, which forgets everything between requests.', 'Set CACHE_STORE=redis.');
        }
    }

    private function checkPayments(bool $production): void
    {
        $provider = (string) Config::get('subscriptions.default');

        if ($production && in_array($provider, ['sandbox', 'null'], true)) {
            $this->fail('subscriptions.default', "The subscription gateway is «{$provider}», which settles nothing real — customers would be activated without money moving, or unable to pay at all.", 'Set SUBSCRIPTION_PROVIDER=moyasar and supply its credentials.');
        }

        foreach (['moyasar' => ['secret_key', 'publishable_key', 'webhook_token'], 'stripe' => ['secret_key', 'publishable_key', 'webhook_secret']] as $gateway => $keys) {
            [$secretKey, $publishableKey, $webhookKey] = $keys;

            $secret = Config::get("services.{$gateway}.{$secretKey}");
            $publishable = Config::get("services.{$gateway}.{$publishableKey}");
            $webhook = Config::get("services.{$gateway}.{$webhookKey}");

            $configured = is_string($secret) && $secret !== '';

            if (! $configured) {
                // Not an error anywhere: an unconfigured gateway reports awaiting_credentials and
                // refuses to open a checkout. It is only a failure when it is the CHOSEN gateway.
                if ($provider === $gateway) {
                    $this->fail("services.{$gateway}", "«{$gateway}» is the chosen gateway and has no secret key, so no checkout can open.", 'Supply its live credentials.');
                }

                continue;
            }

            if (! is_string($webhook) || $webhook === '') {
                $this->fail("services.{$gateway}.{$webhookKey}", "«{$gateway}» has a secret key but no webhook secret — a customer could pay and nothing would ever confirm it, because a webhook that cannot be verified is discarded.", 'Supply the webhook secret from the gateway dashboard.');
            }

            if ($production && $this->isTestKey($secret)) {
                $this->fail("services.{$gateway}.{$secretKey}", "«{$gateway}» is using a TEST secret key in production — real customers would be charged nothing while the product reports them as paid.", 'Replace it with the live key.');
            }

            /*
             * And the other direction, which had nothing watching it at all.
             *
             * A LIVE key outside production is a developer's laptop, a staging box or a CI run
             * charging real cards — real money, from whoever's card is nearest, against an install
             * whose database is thrown away nightly. It is the more expensive half of «test/live
             * separation» and the easier one to do by accident, because copying a working `.env` from
             * production is how most people set up a staging environment.
             */
            if (! $production && ! $this->isTestKey($secret)) {
                $this->fail(
                    "services.{$gateway}.{$secretKey}",
                    "«{$gateway}» is holding a LIVE secret key in the «".Config::get('app.env').'» environment — anything that opens a checkout here takes real money from a real card.',
                    'Use the test key outside production, and keep the live key in the production environment only.',
                );
            }

            if ($production && is_string($publishable) && $this->isTestKey($publishable)) {
                $this->fail("services.{$gateway}.{$publishableKey}", "«{$gateway}» is using a TEST publishable key in production.", 'Replace it with the live publishable key.');
            }

            /*
             * A live secret against a test publishable key (or the reverse) is the mismatch that
             * produces «the payment succeeded in the browser and never existed on the server».
             */
            if (is_string($publishable) && $publishable !== '' && $this->isTestKey($secret) !== $this->isTestKey($publishable)) {
                $this->fail("services.{$gateway}", "«{$gateway}» mixes a test key with a live one — the browser and the server would be talking to two different gateways.", 'Use a matched pair: both test, or both live.');
            }
        }
    }

    /**
     * PAY-TOKEN-003 — can this install take a renewal by itself?
     *
     * A WARNING, because an attended renewal is a real, working way to be paid: the customer gets an
     * invoice and pays it. What makes it worth saying out loud is the shape of the failure when they
     * do not — the period lapses, the account goes past due and then suspended, and from the inside
     * that looks like dunning working correctly rather than like a bill nobody was ever able to pay
     * automatically.
     *
     * Config-derived like everything else here: it asks the adapter what it CAN do, not the database
     * how many cards exist. Whether a particular customer has a card on file is a different question,
     * and `RecurringBilling::modeFor()` is what answers it, per subscription, on their own billing
     * page.
     */
    private function checkRecurringBilling(bool $production): void
    {
        if (! $production) {
            return;
        }

        $provider = (string) Config::get('subscriptions.default');
        $adapter = app(SubscriptionProviderRegistry::class)->for($provider);

        // An unconfigured gateway is already a failure above; saying it twice buries the first.
        if (! $adapter->isConfigured() || $adapter->supportsUnattendedCharge()) {
            return;
        }

        $this->warn(
            'subscriptions.recurring',
            "«{$provider}» cannot charge a saved card, so every renewal is an invoice the customer has to visit and pay. One they miss ends in a past-due account rather than a failed charge.",
            'Use a gateway with unattended charging wired, or make sure somebody is chasing renewals.',
        );
    }

    private function checkMail(): void
    {
        if (in_array(Config::get('mail.default'), ['log', 'array'], true)) {
            $this->warn('mail.default', 'No mail provider is configured, so nothing is delivered. The product already reports this honestly and never records a message as sent.', 'Supply SMTP or API credentials when they exist — READY_FOR_CREDENTIALS until then.');
        }
    }

    /**
     * FX-FEED-001 — the exchange-rate supply.
     *
     * A WARNING and not a failure, for the same reason mail is: the product already tells the truth
     * without it. A figure in a currency with no dated rate is withheld and counted, on the funnel,
     * on the dashboard and on the client's own link — nothing is invented and no total quietly
     * shortens. But a deployment taking real traffic with no rate source will withhold real figures,
     * and that is worth an operator seeing in the pipeline rather than in a client's report.
     */
    private function checkExchangeRates(): void
    {
        $driver = Config::get('fx.rates.driver');

        if (is_string($driver) && $driver !== '') {
            return;
        }

        $this->warn(
            'fx.rates.driver',
            'No exchange-rate source is configured. Money in a currency other than a client’s reporting currency is WITHHELD rather than converted — the figures are reported as withheld, never guessed.',
            'Set FX_RATE_DRIVER to a source class, or record rates by hand at /admin/settings/currency-rates — READY_FOR_CONFIGURATION until then.',
        );
    }

    /** Both gateways prefix their test credentials; anything else is treated as live. */
    private function isTestKey(mixed $key): bool
    {
        return is_string($key) && (str_contains($key, '_test') || str_starts_with($key, 'sk_test') || str_starts_with($key, 'pk_test'));
    }

    private function isLocal(string $host): bool
    {
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.local') || str_ends_with($host, '.test');
    }

    private function fail(string $key, string $message, string $fix): void
    {
        $this->findings[] = ['key' => $key, 'level' => 'fail', 'message' => $message, 'fix' => $fix];
    }

    private function warn(string $key, string $message, string $fix): void
    {
        $this->findings[] = ['key' => $key, 'level' => 'warn', 'message' => $message, 'fix' => $fix];
    }
}
