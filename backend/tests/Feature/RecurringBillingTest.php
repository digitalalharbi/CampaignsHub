<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPaymentMethod;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\RecurringBilling;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PAY-TOKEN-001 — the saved card, and the honest answer about whether renewals can use it.
 *
 * The state this file pins down is the one that was invisible: a renewal opens an invoice nobody
 * visits, the period lapses, and the account goes past due. That reads like dunning working. It is
 * the absence of unattended billing, and the product now says which of three different problems it
 * actually has.
 */
final class RecurringBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A subscription needs a plan to point at; the catalogue is what everything else is priced from.
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function tenant(): Tenant
    {
        return Tenant::create(['name' => 'Payer', 'slug' => 'payer-'.uniqid(), 'status' => 'active']);
    }

    private function subscription(Tenant $tenant, string $provider = 'moyasar'): Subscription
    {
        $subscription = new Subscription;
        $subscription->forceFill([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => SubscriptionPlan::query()->firstOrFail()->getKey(),
            'status' => 'active',
            'billing_interval' => 'monthly',
            'unit_amount' => '49.00',
            'currency' => 'USD',
            'provider' => $provider,
            'current_period_end' => Carbon::now()->addMonth(),
        ])->save();

        return $subscription->refresh();
    }

    private function billing(): RecurringBilling
    {
        return app(RecurringBilling::class);
    }

    private function configureMoyasar(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'tok']);
    }

    // ── the card itself ───────────────────────────────────────────────────────────────────────

    /**
     * The token is encrypted at rest, and the row carries no card.
     *
     * A stored token is a bearer credential: whoever reads the column can charge the card. This
     * asserts against the RAW column rather than the model, because the model would decrypt it and
     * the question is what a database dump contains.
     */
    public function test_the_token_is_encrypted_and_no_card_is_stored(): void
    {
        $tenant = $this->tenant();

        $method = $this->billing()->remember((string) $tenant->getKey(), 'moyasar', [
            'token' => 'tok_live_secret_value',
            'brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030,
        ]);

        $raw = (string) DB::table('subscription_payment_methods')->where('id', $method->getKey())->value('provider_token');

        $this->assertNotSame('tok_live_secret_value', $raw, 'the token is sitting in the clear');
        $this->assertStringNotContainsString('tok_live_secret_value', $raw);
        $this->assertSame('tok_live_secret_value', $method->refresh()->provider_token);

        // Nowhere to put a card, which is stronger than a rule saying nobody will.
        $columns = array_keys((array) DB::table('subscription_payment_methods')->first());
        foreach (['pan', 'number', 'card_number', 'cvc', 'cvv'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    /** The credential never leaves through serialisation, whatever forgets to whitelist columns. */
    public function test_the_token_is_not_serialised(): void
    {
        $tenant = $this->tenant();
        $method = $this->billing()->remember((string) $tenant->getKey(), 'moyasar', ['token' => 'tok_secret']);

        $this->assertStringNotContainsString('tok_secret', json_encode($method->toArray(), JSON_THROW_ON_ERROR));
    }

    /** Re-entering the same card leaves ONE card on file, not two that decline together. */
    public function test_saving_the_same_token_twice_keeps_one_method(): void
    {
        $tenant = $this->tenant();
        $id = (string) $tenant->getKey();

        $this->billing()->remember($id, 'moyasar', ['token' => 'tok_a', 'last4' => '4242']);
        $this->billing()->remember($id, 'moyasar', ['token' => 'tok_a', 'last4' => '4242']);

        $this->assertSame(1, SubscriptionPaymentMethod::query()->where('tenant_id', $id)->count());
    }

    /** A second, different card takes over as the default — and there is only ever one. */
    public function test_a_new_card_becomes_the_only_default(): void
    {
        $tenant = $this->tenant();
        $id = (string) $tenant->getKey();

        $first = $this->billing()->remember($id, 'moyasar', ['token' => 'tok_a', 'last4' => '1111']);
        $second = $this->billing()->remember($id, 'moyasar', ['token' => 'tok_b', 'last4' => '2222']);

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
        $this->assertSame(1, SubscriptionPaymentMethod::query()->where('tenant_id', $id)->where('is_default', true)->count());
    }

    /** Retiring a card keeps the row — it is why last month's charge in the ledger exists. */
    public function test_forgetting_a_card_detaches_rather_than_deletes(): void
    {
        $tenant = $this->tenant();
        $method = $this->billing()->remember((string) $tenant->getKey(), 'moyasar', ['token' => 'tok_a']);

        $this->billing()->forget($method);

        $this->assertDatabaseHas('subscription_payment_methods', ['id' => $method->getKey()]);
        $this->assertFalse($method->refresh()->isUsable());
    }

    /** A card is good through the END of its stated month, not from the first of it. */
    public function test_a_card_expires_at_the_end_of_its_month(): void
    {
        $method = new SubscriptionPaymentMethod;
        $method->forceFill(['exp_month' => 8, 'exp_year' => 2026]);

        $this->assertFalse($method->isExpired(Carbon::parse('2026-08-01')));
        $this->assertFalse($method->isExpired(Carbon::parse('2026-08-31')));
        $this->assertTrue($method->isExpired(Carbon::parse('2026-09-01')));
    }

    /** An unknown expiry is not an expired one — several providers publish none. */
    public function test_an_unknown_expiry_is_not_treated_as_expired(): void
    {
        $method = new SubscriptionPaymentMethod;

        $this->assertFalse($method->isExpired(Carbon::parse('2030-01-01')));
    }

    // ── which mode a renewal is in, and why ───────────────────────────────────────────────────

    /** No gateway at all: the deployment's problem, not the customer's. */
    public function test_with_no_gateway_the_reason_is_the_gateway(): void
    {
        config(['services.moyasar.secret_key' => null, 'services.moyasar.webhook_token' => null]);

        $mode = $this->billing()->modeFor($this->subscription($this->tenant()));

        $this->assertFalse($mode['unattended']);
        $this->assertSame('no_gateway', $mode['reason']);
    }

    /** A configured gateway with no card on file: the customer's problem, and a different message. */
    public function test_with_a_gateway_but_no_card_the_reason_is_the_card(): void
    {
        $this->configureMoyasar();

        $mode = $this->billing()->modeFor($this->subscription($this->tenant()));

        $this->assertFalse($mode['unattended']);
        $this->assertSame('no_saved_method', $mode['reason']);
    }

    /** A gateway whose unattended charging is not wired says so, rather than blaming the card. */
    public function test_a_provider_without_unattended_charging_says_so(): void
    {
        config(['subscriptions.default' => 'sandbox', 'services.sandbox.secret' => 'local-secret']);

        $mode = $this->billing()->modeFor($this->subscription($this->tenant(), 'sandbox'));

        $this->assertFalse($mode['unattended']);
        $this->assertSame('provider_unsupported', $mode['reason']);
    }

    public function test_a_gateway_and_a_card_together_are_ready(): void
    {
        $this->configureMoyasar();
        $tenant = $this->tenant();

        $this->billing()->remember((string) $tenant->getKey(), 'moyasar', [
            'token' => 'tok_a', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2099,
        ]);

        $mode = $this->billing()->modeFor($this->subscription($tenant));

        $this->assertTrue($mode['unattended']);
        $this->assertSame('ready', $mode['reason']);
        $this->assertSame('visa ···· 4242', $mode['method']);
    }

    /**
     * A card the gateway did not mint is not a card this gateway can charge.
     *
     * Charging a Stripe token through Moyasar is a lookup against the wrong vault, and «no such
     * token» reads to everybody downstream as a declined card the customer has to go and fix.
     */
    public function test_a_card_saved_for_another_gateway_is_not_used(): void
    {
        $this->configureMoyasar();
        $tenant = $this->tenant();

        $this->billing()->remember((string) $tenant->getKey(), 'stripe', ['token' => 'tok_stripe']);

        $this->assertNull($this->billing()->methodFor($this->subscription($tenant, 'moyasar')));
    }

    /** An expired card is skipped before a charge is spent proving what we already knew. */
    public function test_an_expired_card_is_not_offered_for_a_renewal(): void
    {
        $this->configureMoyasar();
        $tenant = $this->tenant();

        $this->billing()->remember((string) $tenant->getKey(), 'moyasar', [
            'token' => 'tok_old', 'exp_month' => 1, 'exp_year' => 2020,
        ]);

        // One subscription per tenant — the table says so, and reusing it is what a renewal does.
        $subscription = $this->subscription($tenant);

        $this->assertNull($this->billing()->methodFor($subscription));
        $this->assertSame('no_saved_method', $this->billing()->modeFor($subscription)['reason']);
    }

    /** A detached card is not quietly re-used. */
    public function test_a_detached_card_is_not_offered(): void
    {
        $this->configureMoyasar();
        $tenant = $this->tenant();
        $method = $this->billing()->remember((string) $tenant->getKey(), 'moyasar', ['token' => 'tok_a']);

        $this->billing()->forget($method);

        $this->assertNull($this->billing()->methodFor($this->subscription($tenant)));
    }

    // ── the platform-wide answer ──────────────────────────────────────────────────────────────

    /**
     * `readiness()` is about the GATEWAY, and must not be read as «every subscriber will renew».
     *
     * The two are separate questions with separate answers, and conflating them is how an operator
     * concludes that recurring billing is working while every customer without a card on file
     * quietly lapses.
     */
    public function test_readiness_is_about_the_gateway_not_about_any_customer(): void
    {
        $this->configureMoyasar();
        config(['subscriptions.default' => 'moyasar']);

        $readiness = $this->billing()->readiness();

        $this->assertTrue($readiness['ready']);
        $this->assertSame('moyasar', $readiness['provider']);
        $this->assertSame(0, $readiness['saved_methods'], 'ready, and nobody has a card on file');
    }

    public function test_readiness_names_the_missing_gateway(): void
    {
        config(['subscriptions.default' => 'moyasar', 'services.moyasar.secret_key' => null, 'services.moyasar.webhook_token' => null]);

        $readiness = $this->billing()->readiness();

        $this->assertFalse($readiness['ready']);
        $this->assertSame('no_gateway', $readiness['reason']);
    }
}
