<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Platform\Services\ProductionReadiness;
use Tests\TestCase;

/**
 * PROD-CONFIG-001 — the checks that stand between a deploy and a customer paying into nothing.
 *
 * Each case sets ONE thing wrong and asserts that this specific key is named. A test that merely
 * counted failures would keep passing after a check was accidentally deleted, which is the failure
 * mode a readiness check cannot afford.
 */
final class ProductionReadinessTest extends TestCase
{
    /** A production install with everything right — the baseline every case below deviates from. */
    private function productionConfig(array $over = []): void
    {
        config(array_merge([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://api.campaignshub.io',
            'brand.frontend_url' => 'https://campaignshub.io',
            'session.secure' => true,
            'session.domain' => '.campaignshub.io',
            'database.default' => 'pgsql',
            'queue.default' => 'redis',
            'cache.default' => 'redis',
            'mail.default' => 'smtp',
            'subscriptions.default' => 'moyasar',
            'services.moyasar.secret_key' => 'sk_live_abc',
            'services.moyasar.publishable_key' => 'pk_live_abc',
            'services.moyasar.webhook_token' => 'a-real-token',
            'services.stripe.secret_key' => null,
            'services.stripe.publishable_key' => null,
            'services.stripe.webhook_secret' => null,
        ], $over));
    }

    /** @return list<string> */
    private function failedKeys(): array
    {
        $report = (new ProductionReadiness)->run();

        return array_values(array_map(
            fn (array $f) => $f['key'],
            array_filter($report['findings'], fn (array $f) => $f['level'] === 'fail'),
        ));
    }

    public function test_a_correctly_configured_production_install_is_ready(): void
    {
        $this->productionConfig();

        $report = (new ProductionReadiness)->run();

        $this->assertTrue($report['ready'], 'a correct install must not report failures: '.json_encode($report['findings']));
        $this->assertSame(0, $report['failures']);
    }

    public function test_debug_mode_in_production_is_a_failure(): void
    {
        $this->productionConfig(['app.debug' => true]);

        $this->assertContains('app.debug', $this->failedKeys());
    }

    public function test_localhost_cannot_be_the_public_url_in_production(): void
    {
        $this->productionConfig(['app.url' => 'https://localhost']);

        $this->assertContains('app.url', $this->failedKeys());
    }

    public function test_a_plain_http_url_is_refused_in_production(): void
    {
        $this->productionConfig(['app.url' => 'http://api.campaignshub.io']);

        $this->assertContains('app.url', $this->failedKeys());
    }

    /**
     * A cookie domain that does not cover the app's own host reads to a person as «sign-in does
     * nothing»: the browser accepts the response and stores no cookie.
     */
    public function test_a_session_domain_that_cannot_hold_the_cookie_is_a_failure(): void
    {
        $this->productionConfig(['session.domain' => '.example.com']);

        $this->assertContains('session.domain', $this->failedKeys());
    }

    public function test_a_parent_domain_is_accepted_for_the_session_cookie(): void
    {
        $this->productionConfig(['session.domain' => '.campaignshub.io', 'app.url' => 'https://app.campaignshub.io']);

        $this->assertNotContains('session.domain', $this->failedKeys());
    }

    public function test_a_test_secret_key_in_production_is_a_failure(): void
    {
        $this->productionConfig(['services.moyasar.secret_key' => 'sk_test_abc', 'services.moyasar.publishable_key' => 'pk_test_abc']);

        $this->assertContains('services.moyasar.secret_key', $this->failedKeys());
    }

    /** A live secret against a test publishable key is «it worked in the browser and never existed». */
    public function test_mixing_a_test_key_with_a_live_one_is_a_failure(): void
    {
        $this->productionConfig(['services.moyasar.publishable_key' => 'pk_test_abc']);

        $this->assertContains('services.moyasar', $this->failedKeys());
    }

    public function test_a_gateway_with_no_webhook_secret_is_a_failure(): void
    {
        $this->productionConfig(['services.moyasar.webhook_token' => null]);

        $this->assertContains('services.moyasar.webhook_token', $this->failedKeys());
    }

    public function test_the_sandbox_gateway_cannot_be_the_production_gateway(): void
    {
        $this->productionConfig(['subscriptions.default' => 'sandbox']);

        $this->assertContains('subscriptions.default', $this->failedKeys());
    }

    public function test_a_synchronous_queue_is_refused_in_production(): void
    {
        $this->productionConfig(['queue.default' => 'sync']);

        $this->assertContains('queue.default', $this->failedKeys());
    }

    /**
     * An unconfigured mail provider is a WARNING, not a failure.
     *
     * The product never records a message as sent without one — that is a decision with tests
     * behind it, so it is an unfinished integration rather than a hole.
     */
    public function test_a_missing_mail_provider_warns_and_does_not_block(): void
    {
        $this->productionConfig(['mail.default' => 'log']);

        $report = (new ProductionReadiness)->run();

        $this->assertTrue($report['ready']);
        $this->assertContains('mail.default', array_column($report['findings'], 'key'));
    }

    /**
     * FX-FEED-001 — an absent exchange-rate source warns and does not block.
     *
     * The same reasoning as mail: without one, money in another currency is WITHHELD and reported as
     * withheld, never guessed — so the product tells the truth without it. What the warning buys is
     * an operator learning it in the pipeline rather than from a client asking why a total is short.
     */
    public function test_a_missing_exchange_rate_source_warns_and_does_not_block(): void
    {
        $this->productionConfig(['fx.rates.driver' => null]);

        $report = (new ProductionReadiness)->run();

        $this->assertTrue($report['ready']);
        $this->assertContains('fx.rates.driver', array_column($report['findings'], 'key'));
    }

    /** With a source named, the warning goes away — the check is about the decision, not the data. */
    public function test_a_configured_exchange_rate_source_clears_the_warning(): void
    {
        $this->productionConfig(['fx.rates.driver' => 'App\\Some\\RateSource']);

        $report = (new ProductionReadiness)->run();

        $this->assertNotContains('fx.rates.driver', array_column($report['findings'], 'key'));
    }

    /**
     * PAY-ENV-001 — a LIVE key outside production is a failure, and the more expensive one.
     *
     * The check above catches a test key in production, where the symptom is nobody being charged.
     * This is the other direction: a laptop, a staging box or a CI run holding the live key charges
     * REAL cards against a database that is thrown away nightly. It is also the easier mistake to
     * make, because copying a working `.env` from production is how most staging environments start.
     */
    public function test_a_live_secret_key_outside_production_is_a_failure(): void
    {
        $this->productionConfig(['app.env' => 'staging']);

        $this->assertContains('services.moyasar.secret_key', $this->failedKeys());
    }

    /** The test key in a development environment is exactly right, and must not be nagged about. */
    public function test_a_test_key_outside_production_is_correct(): void
    {
        $this->productionConfig([
            'app.env' => 'local',
            'services.moyasar.secret_key' => 'sk_test_abc',
            'services.moyasar.publishable_key' => 'pk_test_abc',
        ]);

        $this->assertNotContains('services.moyasar.secret_key', $this->failedKeys());
    }

    /**
     * PAY-TOKEN-003 — a gateway that cannot charge a saved card warns, and does not block.
     *
     * An attended renewal is a real way to be paid; what makes it worth saying is the shape of the
     * failure when a customer misses the invoice — the account goes past due and then suspended,
     * which reads like dunning working rather than like a bill nobody could pay automatically.
     */
    public function test_a_gateway_that_cannot_renew_unattended_warns_and_does_not_block(): void
    {
        $this->productionConfig([
            'subscriptions.default' => 'stripe',
            'services.stripe.secret_key' => 'sk_live_abc',
            'services.stripe.publishable_key' => 'pk_live_abc',
            'services.stripe.webhook_secret' => 'whsec_abc',
        ]);

        $report = (new ProductionReadiness)->run();

        $this->assertTrue($report['ready']);
        $this->assertContains('subscriptions.recurring', array_column($report['findings'], 'key'));
    }

    /** Moyasar can, so there is nothing to say. */
    public function test_a_gateway_with_unattended_charging_raises_no_warning(): void
    {
        $this->productionConfig();

        $report = (new ProductionReadiness)->run();

        $this->assertNotContains('subscriptions.recurring', array_column($report['findings'], 'key'));
    }

    /** A development install is not held to production's rules, and must not be. */
    public function test_local_development_is_not_judged_against_production(): void
    {
        $this->productionConfig([
            'app.env' => 'local',
            'app.url' => 'http://localhost:8000',
            'session.secure' => false,
            'subscriptions.default' => 'sandbox',
            'services.moyasar.secret_key' => 'sk_test_abc',
            'services.moyasar.publishable_key' => 'pk_test_abc',
        ]);

        $this->assertSame([], $this->failedKeys());
    }

    /** No check may return a secret — the report is read by people who must not see the keys. */
    public function test_the_report_never_carries_a_secret(): void
    {
        $this->productionConfig(['services.moyasar.secret_key' => 'sk_live_SUPERSECRETVALUE', 'services.moyasar.publishable_key' => 'pk_test_x']);

        $encoded = (string) json_encode((new ProductionReadiness)->run());

        $this->assertStringNotContainsString('SUPERSECRETVALUE', $encoded);
    }

    public function test_the_command_exits_non_zero_when_something_fails(): void
    {
        $this->productionConfig(['app.debug' => true]);

        $this->artisan('production:check')->assertExitCode(1);
    }

    public function test_the_command_exits_zero_on_a_correct_install(): void
    {
        $this->productionConfig();

        $this->artisan('production:check')->assertExitCode(0);
    }
}
