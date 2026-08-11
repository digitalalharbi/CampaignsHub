<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Billing\Providers\MoyasarPaymentProvider;
use App\Domains\Billing\Providers\SandboxPaymentProvider;
use App\Domains\Billing\Providers\StripePaymentProvider;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPaymentMethod;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\RecurringBilling;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * PAY-TOKEN-003 — the card on file that no install could ever acquire.
 *
 * ## The defect, stated plainly
 *
 * `RecurringBilling::remember()` existed, was tested, encrypted its token and picked a default. It
 * had **no caller anywhere in the application** — only this suite's ancestor. So
 * `subscription_payment_methods` was empty in every real deployment, `methodFor()` always answered
 * null, and the fork in `SubscriptionCheckout::open()` that debits a saved card could not fire for
 * anybody. Every renewal, for every customer, was a hosted invoice somebody had to remember to visit.
 *
 * That failure is quiet in the worst way. The customer agreed to automatic renewal before they paid
 * (SUB-CONSENT-001), nothing charged them, the period lapsed, `markPastDue` fired on schedule and the
 * account was suspended after grace. From the outside it looks exactly like dunning working
 * correctly — and the two tests at the top of this file are the difference.
 *
 * ## What is verified here, and what is not
 *
 * Verified: a settled payment that carries a token files a card; a settled payment that does not
 * files nothing; the next renewal is debited rather than invoiced; the token never leaves the server
 * and never reaches the audit trail; a customer can take the card off file and keep their
 * subscription.
 *
 * NOT verified, and the reason this unit is READY_FOR_CREDENTIALS: that Moyasar's live payload puts
 * the token where the adapter reads it. No key exists in this repository to have seen a real one. The
 * refusal is what is proven — no token, no card, no charge.
 */
final class SavedPaymentMethodTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_token' => 'the-token']);
        config(['subscriptions.default' => 'moyasar']);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    /**
     * A workspace that is already trading.
     *
     * `account_state` matters as much as `status`: a renewal confirmation drives the account to
     * Active through the state machine, and a tenant left in `draft` cannot make that move — the
     * settlement would fail for a reason that has nothing to do with cards.
     */
    private function tenant(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Payer', 'slug' => 'payer-'.uniqid(), 'status' => 'active']);
        $tenant->forceFill(['account_state' => AccountState::Active->value])->save();

        return $tenant->refresh();
    }

    private function subscription(Tenant $tenant): Subscription
    {
        $subscription = new Subscription;
        $subscription->forceFill([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => SubscriptionPlan::query()->firstOrFail()->getKey(),
            'status' => 'active',
            'billing_interval' => 'monthly',
            'unit_amount' => '49.00',
            'currency' => 'USD',
            'provider' => 'moyasar',
            'current_period_end' => Carbon::now()->addMonth(),
        ])->save();

        return $subscription->refresh();
    }

    /** A renewal charge, opened and waiting for the gateway to confirm it. */
    private function pendingRenewal(Subscription $subscription): SubscriptionPayment
    {
        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->getKey(),
            'purpose' => 'subscription',
            'provider' => 'moyasar',
            'amount' => '49.00',
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => 'subscription:'.$subscription->getKey().':'.$subscription->current_period_end?->toDateString(),
        ])->save();

        return $payment->refresh();
    }

    /**
     * Moyasar's answer to `GET /v1/payments/{id}` — the re-read every settlement goes through
     * (PAY-CONFIRM-001). The source travels on the webhook body, not on this response, so the two are
     * kept separate exactly as they are in production.
     */
    private function gatewayConfirms(SubscriptionPayment $payment): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments/*' => Http::response([
                'id' => 'pay_1',
                'status' => 'paid',
                'amount' => (int) round((float) $payment->amount * 100),
                'currency' => $payment->currency,
                'metadata' => ['reference' => $payment->idempotency_key],
            ], 200),
        ]);
    }

    /**
     * A verified Moyasar event for this charge. `$source` is what the gateway says about the card —
     * the whole subject of this file.
     *
     * @param  array<string,mixed>|null  $source
     */
    private function webhook(SubscriptionPayment $payment, ?array $source, string $status = 'paid', string $eventId = 'evt_1'): void
    {
        $data = [
            'id' => 'pay_1',
            'status' => $status,
            'amount' => (int) round((float) $payment->amount * 100),
            'currency' => $payment->currency,
            'metadata' => ['reference' => $payment->idempotency_key],
        ];

        if ($source !== null) {
            $data['source'] = $source;
        }

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => $eventId,
            'type' => 'payment_'.$status,
            'secret_token' => 'the-token',
            'data' => $data,
        ])->assertOk()->assertJsonPath('data.verified', true);
    }

    /** The card Moyasar would publish with a payment made from a reusable source. */
    private function tokenSource(string $token = 'token_abc'): array
    {
        return [
            'type' => 'creditcard',
            'company' => 'visa',
            'number' => '4111-11XX-XXXX-4242',
            'month' => 8,
            'year' => 27,
            'token' => $token,
        ];
    }

    // ── the headline ──────────────────────────────────────────────────────────────────────────

    /**
     * A settled payment now leaves a card behind, and the next renewal is TAKEN rather than asked for.
     *
     * This is the whole unit in one test. Before it, the second half was unreachable: no code path
     * put a row in `subscription_payment_methods`, so `methodFor()` returned null for every customer
     * in every install and the renewal fork could not fire.
     */
    public function test_a_settled_payment_puts_the_card_on_file_and_the_next_renewal_uses_it(): void
    {
        $tenant = $this->tenant();
        $subscription = $this->subscription($tenant);
        $payment = $this->pendingRenewal($subscription);

        $this->assertNull(
            app(RecurringBilling::class)->methodFor($subscription),
            'nothing may be on file before a payment settles',
        );

        $this->gatewayConfirms($payment);
        $this->webhook($payment, $this->tokenSource());

        $this->assertSame('paid', $payment->refresh()->status);

        $method = app(RecurringBilling::class)->methodFor($subscription);

        $this->assertNotNull($method, 'the settled payment must have left a card the renewal can use');
        $this->assertSame('token_abc', $method->provider_token);
        // The brand is kept as the gateway writes it. Title-casing it would render «mada» — a brand
        // that is deliberately lowercase — as something its own owner does not call it.
        $this->assertSame('visa ···· 4242', $method->label());

        // And the mode flips from «somebody has to visit an invoice» to «we can take it».
        $mode = app(RecurringBilling::class)->modeFor($subscription->refresh());
        $this->assertTrue($mode['unattended']);
        $this->assertSame('ready', $mode['reason']);
    }

    /**
     * The common case, and it must stay quiet: a payment with no reusable source files nothing.
     *
     * Every Moyasar payment made with a bare card, and every payment on an account without
     * card-on-file enabled, lands here. The customer loses nothing — their renewal is the attended
     * invoice it always was — and, crucially, no token is invented to fill the gap.
     */
    public function test_a_settled_payment_without_a_token_files_no_card(): void
    {
        $subscription = $this->subscription($this->tenant());
        $payment = $this->pendingRenewal($subscription);

        $this->gatewayConfirms($payment);
        $this->webhook($payment, ['type' => 'creditcard', 'company' => 'mada', 'number' => '5555-55XX-XXXX-1111']);

        $this->assertSame('paid', $payment->refresh()->status, 'the payment itself must still settle');
        $this->assertSame(0, SubscriptionPaymentMethod::count());
        $this->assertSame('no_saved_method', app(RecurringBilling::class)->modeFor($subscription)['reason']);
    }

    // ── what the adapter reads, and what it refuses to ────────────────────────────────────────

    /** The token is the only load-bearing field, and its absence is the whole test. */
    public function test_the_adapter_returns_nothing_without_an_explicit_token(): void
    {
        $adapter = new MoyasarPaymentProvider;

        $this->assertNull($adapter->savedPaymentMethodFrom([]));
        $this->assertNull($adapter->savedPaymentMethodFrom(['data' => ['id' => 'pay_1']]));
        $this->assertNull($adapter->savedPaymentMethodFrom(['data' => ['source' => ['type' => 'creditcard', 'number' => '4111-11XX-XXXX-4242']]]));
        $this->assertNull($adapter->savedPaymentMethodFrom(['data' => ['source' => ['token' => '   ']]]));
    }

    /** The labels beside the token: brand, last four and expiry, each optional. */
    public function test_the_adapter_reads_the_card_labels_beside_the_token(): void
    {
        $card = (new MoyasarPaymentProvider)->savedPaymentMethodFrom(['data' => ['source' => $this->tokenSource('token_xyz')]]);

        $this->assertSame('token_xyz', $card['token']);
        $this->assertSame('visa', $card['brand']);
        $this->assertSame('4242', $card['last4']);
        $this->assertSame(8, $card['exp_month']);
    }

    /**
     * A two-digit year is this decade, not the first century.
     *
     * Stored raw, `27` becomes a card that expired two millennia ago, and
     * `SubscriptionPaymentMethod::isExpired()` — correctly, on the data it was handed — would refuse
     * a live card on the customer's very first renewal.
     */
    public function test_a_two_digit_expiry_year_is_read_as_this_century(): void
    {
        $adapter = new MoyasarPaymentProvider;

        $this->assertSame(2027, $adapter->savedPaymentMethodFrom(['data' => ['source' => ['token' => 't', 'year' => 27]]])['exp_year']);
        $this->assertSame(2027, $adapter->savedPaymentMethodFrom(['data' => ['source' => ['token' => 't', 'year' => 2027]]])['exp_year']);
        $this->assertNull($adapter->savedPaymentMethodFrom(['data' => ['source' => ['token' => 't']]])['exp_year']);
    }

    /** A month outside 1–12 is not a month, and a label nobody can trust is better absent. */
    public function test_an_impossible_expiry_month_is_dropped_rather_than_stored(): void
    {
        $adapter = new MoyasarPaymentProvider;

        $this->assertNull($adapter->savedPaymentMethodFrom(['data' => ['source' => ['token' => 't', 'month' => 13]]])['exp_month']);
        $this->assertNull($adapter->savedPaymentMethodFrom(['data' => ['source' => ['token' => 't', 'month' => 0]]])['exp_month']);
    }

    /** A masked number too short to end in four digits yields no last four, rather than a wrong one. */
    public function test_a_number_that_cannot_give_four_digits_gives_none(): void
    {
        $card = (new MoyasarPaymentProvider)->savedPaymentMethodFrom(['data' => ['source' => ['token' => 't', 'number' => 'XXX']]]);

        $this->assertNull($card['last4']);
    }

    /**
     * Neither the sandbox nor Stripe files a card, and both for the same reason.
     *
     * Each has already answered `supportsUnattendedCharge() === false`. A card on file that the
     * gateway cannot charge would tell the customer they are set up for automatic payment — the one
     * thing they most need to know is untrue.
     */
    public function test_a_provider_that_cannot_charge_a_token_does_not_store_one(): void
    {
        $payload = ['data' => ['source' => $this->tokenSource()], 'data.object' => []];

        $this->assertNull((new SandboxPaymentProvider)->savedPaymentMethodFrom($payload));
        $this->assertNull((new StripePaymentProvider)->savedPaymentMethodFrom($payload));
    }

    // ── when a card is filed, and when it is not ──────────────────────────────────────────────

    /** A charge that did not settle proves nothing about a card, so nothing is kept. */
    public function test_a_failed_payment_files_no_card(): void
    {
        $subscription = $this->subscription($this->tenant());
        $payment = $this->pendingRenewal($subscription);

        $this->webhook($payment, $this->tokenSource(), status: 'failed');

        $this->assertSame('failed', $payment->refresh()->status);
        $this->assertSame(0, SubscriptionPaymentMethod::count());
    }

    /**
     * A re-delivered event does not file the card twice.
     *
     * Gateways retry by design. Two rows for one card would both be «default», and the renewal sweep
     * would pick between them arbitrarily.
     */
    public function test_a_redelivered_event_leaves_one_card(): void
    {
        $subscription = $this->subscription($this->tenant());
        $payment = $this->pendingRenewal($subscription);

        $this->gatewayConfirms($payment);
        $this->webhook($payment, $this->tokenSource(), eventId: 'evt_1');
        $this->webhook($payment, $this->tokenSource(), eventId: 'evt_2');

        $this->assertSame(1, SubscriptionPaymentMethod::count());
    }

    /** The card belongs to the tenant that paid, and to nobody else. */
    public function test_the_card_is_filed_against_the_tenant_that_paid(): void
    {
        $payer = $this->tenant();
        $other = $this->tenant();
        $payment = $this->pendingRenewal($this->subscription($payer));

        $this->gatewayConfirms($payment);
        $this->webhook($payment, $this->tokenSource());

        $method = SubscriptionPaymentMethod::query()->firstOrFail();
        $this->assertSame((string) $payer->getKey(), (string) $method->tenant_id);
        $this->assertNotSame((string) $other->getKey(), (string) $method->tenant_id);
    }

    // ── the token never leaves ────────────────────────────────────────────────────────────────

    /**
     * The audit trail records the CARD, never the credential.
     *
     * An audit log is read by support staff and exported to whoever asks for it. A bearer token for
     * somebody's card in that export is a wallet in a spreadsheet.
     */
    public function test_the_audit_trail_names_the_card_and_never_the_token(): void
    {
        $payment = $this->pendingRenewal($this->subscription($this->tenant()));

        $this->gatewayConfirms($payment);
        $this->webhook($payment, $this->tokenSource('token_supersecret'));

        $saved = AuditLog::query()->where('action', 'subscription.payment_method.saved')->firstOrFail();

        $this->assertStringContainsString('4242', (string) json_encode($saved->after));
        $this->assertStringNotContainsString('token_supersecret', (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    // ── what the customer sees, and what they can do about it ────────────────────────────────

    /**
     * A user of this workspace, holding exactly the permissions named.
     *
     * The tenant context is set because the endpoints resolve their tenant from it, exactly as the
     * `tenant` middleware does for a real request.
     */
    private function member(Tenant $tenant, string ...$permissions): User
    {
        app(TenantContext::class)->setTenantId((string) $tenant->getKey());

        $role = Role::create(['tenant_id' => $tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);

        $user = User::create([
            'name' => 'M', 'email' => 'm-'.uniqid().'@a.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return $user;
    }

    /**
     * The billing page says HOW the next payment will be taken, and names the card — never the token.
     *
     * Until now it said nothing at all, which meant a customer who had agreed to automatic renewal
     * had no way to find out the product could not perform one. The first sign was a past-due notice.
     */
    public function test_the_billing_page_reports_the_renewal_mode_and_the_card_label(): void
    {
        $tenant = $this->tenant();
        $subscription = $this->subscription($tenant);
        $payment = $this->pendingRenewal($subscription);

        $this->gatewayConfirms($payment);
        $this->webhook($payment, $this->tokenSource('token_supersecret'));

        $user = $this->member($tenant, 'subscriptions.view');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('data.renewal.unattended', true)
            ->assertJsonPath('data.renewal.reason', 'ready')
            ->assertJsonPath('data.renewal.card', 'visa ···· 4242');

        $this->assertStringNotContainsString('token_supersecret', $response->getContent());
    }

    /**
     * With no card, the page says which of the four reasons applies rather than staying silent.
     *
     * `no_saved_method` is the customer's to fix; `no_gateway` and `provider_unsupported` belong to
     * whoever runs the install. Collapsing them would ask the wrong person to act.
     */
    public function test_the_billing_page_names_the_reason_a_renewal_cannot_be_taken(): void
    {
        $tenant = $this->tenant();
        $this->subscription($tenant);
        $user = $this->member($tenant, 'subscriptions.view');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('data.renewal.unattended', false)
            ->assertJsonPath('data.renewal.reason', 'no_saved_method')
            ->assertJsonPath('data.renewal.card', null);
    }

    /**
     * The customer takes the card off file, and their subscription survives it.
     *
     * «Remove my card» and «cancel my subscription» are easy to confuse and only one of them is what
     * this endpoint does — so the subscription is asserted still active, and the renewal falls back
     * to the invoice it was before.
     */
    public function test_a_customer_detaches_the_card_and_keeps_the_subscription(): void
    {
        $tenant = $this->tenant();
        $subscription = $this->subscription($tenant);
        $payment = $this->pendingRenewal($subscription);

        $this->gatewayConfirms($payment);
        $this->webhook($payment, $this->tokenSource());

        $user = $this->member($tenant, 'subscriptions.view', 'subscriptions.manage');

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/subscriptions/payment-method')
            ->assertOk()
            ->assertJsonPath('data.renewal.unattended', false)
            ->assertJsonPath('data.renewal.reason', 'no_saved_method');

        $this->assertSame('active', $subscription->refresh()->status, 'removing a card cancels nothing');

        // Detached, not deleted: the row is why last month's charge in the ledger exists.
        $this->assertSame(1, SubscriptionPaymentMethod::count());
        $this->assertNotNull(SubscriptionPaymentMethod::query()->firstOrFail()->detached_at);
    }

    /** Reading the billing page is not permission to change how the account is billed. */
    public function test_detaching_a_card_needs_the_manage_permission(): void
    {
        $tenant = $this->tenant();
        $subscription = $this->subscription($tenant);
        $payment = $this->pendingRenewal($subscription);

        $this->gatewayConfirms($payment);
        $this->webhook($payment, $this->tokenSource());

        $user = $this->member($tenant, 'subscriptions.view');

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/subscriptions/payment-method')->assertForbidden();

        $this->assertNull(SubscriptionPaymentMethod::query()->firstOrFail()->detached_at);
    }

    /** A workspace with no card is told so plainly, rather than being handed an error. */
    public function test_detaching_when_there_is_no_card_is_not_an_error(): void
    {
        $tenant = $this->tenant();
        $this->subscription($tenant);
        $user = $this->member($tenant, 'subscriptions.view', 'subscriptions.manage');

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/subscriptions/payment-method')
            ->assertOk()
            ->assertJsonPath('data.renewal.reason', 'no_saved_method');
    }

    /** And it is ciphertext in the database, not a token a dump would hand over. */
    public function test_the_stored_token_is_encrypted_at_rest(): void
    {
        $payment = $this->pendingRenewal($this->subscription($this->tenant()));

        $this->gatewayConfirms($payment);
        $this->webhook($payment, $this->tokenSource('token_supersecret'));

        $raw = (string) DB::table('subscription_payment_methods')->value('provider_token');

        $this->assertNotSame('token_supersecret', $raw);
        $this->assertStringNotContainsString('token_supersecret', $raw);
    }
}
