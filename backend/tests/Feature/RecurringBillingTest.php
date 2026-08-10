<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPaymentMethod;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\RecurringBilling;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    // ── PAY-TOKEN-002: the renewal actually taken from the card ───────────────────────────────

    /** Moyasar answers both endpoints this fork can touch: the hosted invoice and the token charge. */
    private function fakeMoyasar(int $chargeStatus = 200): void
    {
        Http::fake([
            'api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_1', 'url' => 'https://pay.example/inv_1'], 200),
            'api.moyasar.com/v1/payments' => $chargeStatus === 200
                ? Http::response(['id' => 'pay_unattended_1', 'status' => 'initiated'], 200)
                : Http::response(['message' => 'declined'], $chargeStatus),
        ]);
    }

    private function checkout(): SubscriptionCheckout
    {
        return app(SubscriptionCheckout::class);
    }

    /**
     * A renewal with a card on file is TAKEN, not asked for — and there is no page to pay.
     *
     * The absent `checkout_url` is the assertion that matters. A charge that both debited the card
     * and produced an invoice would look entirely normal in the interface until the customer's
     * statement arrived with two lines on it.
     */
    public function test_a_renewal_with_a_saved_card_debits_it_and_opens_no_page(): void
    {
        $this->configureMoyasar();
        $this->fakeMoyasar();
        $tenant = $this->tenant();
        $this->billing()->remember((string) $tenant->getKey(), 'moyasar', [
            'token' => 'tok_live', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2099,
        ]);

        $result = $this->checkout()->chargeSubscription($this->subscription($tenant), 'subscription');

        $this->assertNull($result['checkout_url'], 'a page to pay was opened beside a debited card');
        $this->assertSame('created', $result['status']);
        $this->assertSame('pay_unattended_1', $result['payment']->refresh()->provider_payment_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/payments'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/invoices'));
    }

    /**
     * «Submitted» is not «paid». The payment stays pending until a verified webhook says otherwise.
     *
     * Settling here would be a second, weaker way to activate an account — on the word of the party
     * being paid, and without the re-read PAY-CONFIRM-001 exists for.
     */
    public function test_an_unattended_charge_does_not_settle_anything(): void
    {
        $this->configureMoyasar();
        $this->fakeMoyasar();
        $tenant = $this->tenant();
        $this->billing()->remember((string) $tenant->getKey(), 'moyasar', ['token' => 'tok_live']);

        $payment = $this->checkout()->chargeSubscription($this->subscription($tenant), 'subscription')['payment'];

        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertNull($payment->paid_at);
    }

    /** The card that paid is stamped, so «last used» is a fact rather than a guess. */
    public function test_a_successful_attempt_stamps_the_card(): void
    {
        $this->configureMoyasar();
        $this->fakeMoyasar();
        $tenant = $this->tenant();
        $method = $this->billing()->remember((string) $tenant->getKey(), 'moyasar', ['token' => 'tok_live']);

        $this->checkout()->chargeSubscription($this->subscription($tenant), 'subscription');

        $this->assertNotNull($method->refresh()->last_used_at);
    }

    /**
     * A refusal is recorded and stops there — no hosted page is offered as a second attempt.
     *
     * From here a decline and a timeout look identical, and offering a page to pay for something that
     * may already have been taken is exactly the risk this fork avoids. The customer is not stranded:
     * the sweep moves the subscription to past due with its grace period.
     */
    public function test_a_refused_unattended_charge_is_recorded_and_not_retried_behind_a_page(): void
    {
        $this->configureMoyasar();
        $this->fakeMoyasar(chargeStatus: 402);
        $tenant = $this->tenant();
        $this->billing()->remember((string) $tenant->getKey(), 'moyasar', ['token' => 'tok_live']);

        $result = $this->checkout()->chargeSubscription($this->subscription($tenant), 'subscription');

        $this->assertSame('failed', $result['status']);
        $this->assertNull($result['checkout_url']);
        $this->assertSame('failed', $result['payment']->refresh()->status);
        $this->assertNotNull($result['payment']->error);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/invoices'));
    }

    /** No card on file: the hosted page is exactly what it always was. */
    public function test_a_renewal_without_a_saved_card_still_opens_a_page(): void
    {
        $this->configureMoyasar();
        $this->fakeMoyasar();

        $result = $this->checkout()->chargeSubscription($this->subscription($this->tenant()), 'subscription');

        $this->assertSame('https://pay.example/inv_1', $result['checkout_url']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/invoices'));
    }

    /**
     * The fork is narrow on purpose: only the sweep's own renewals.
     *
     * A reactivation happens with the customer in front of the screen, and the card on file is
     * usually the one that just failed — silently charging it again would spend a second decline and
     * tell them nothing. A plan change is the same shape: somebody is there, so they get the page.
     */
    public function test_a_reactivation_keeps_the_hosted_page_even_with_a_card_on_file(): void
    {
        $this->configureMoyasar();
        $this->fakeMoyasar();
        $tenant = $this->tenant();
        $this->billing()->remember((string) $tenant->getKey(), 'moyasar', ['token' => 'tok_live']);

        $result = $this->checkout()->chargeSubscription($this->subscription($tenant), 'reactivation');

        $this->assertSame('https://pay.example/inv_1', $result['checkout_url']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/payments'));
    }

    /**
     * Two sweeps in one period take the money ONCE.
     *
     * The idempotency key is derived from what the charge is, not from when it was asked for, so the
     * second call finds the open charge and hands it back rather than reaching the gateway again.
     */
    public function test_the_sweep_running_twice_charges_the_card_once(): void
    {
        $this->configureMoyasar();
        $this->fakeMoyasar();
        $tenant = $this->tenant();
        $this->billing()->remember((string) $tenant->getKey(), 'moyasar', ['token' => 'tok_live']);
        $subscription = $this->subscription($tenant);

        $first = $this->checkout()->chargeSubscription($subscription, 'subscription')['payment'];
        $second = $this->checkout()->chargeSubscription($subscription->refresh(), 'subscription')['payment'];

        $this->assertTrue($first->is($second));
        Http::assertSentCount(1);
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
