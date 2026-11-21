<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\PaymentAttempt;
use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Billing\Providers\PaymentProvider;
use App\Domains\Billing\Services\BillingService;
use App\Domains\Billing\Support\WebhookPayloadRedactor;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * PAY-WEBHOOK-SECRET-REDACT-001 — the payment webhook ledger never stores the secret that authenticated the event.
 *
 * Moyasar carries its shared webhook secret inside the JSON body (`secret_token`), and `payment_webhook_events.payload`
 * kept the body whole, so every row held the live secret. Verification is unchanged — it reads the raw body first —
 * only the stored copy is redacted. The first two tests fail on `origin/main` `071cca0c0`.
 */
final class PaymentWebhookSecretRedactionTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    private function storedText(): string
    {
        return (string) json_encode(DB::table('payment_webhook_events')->pluck('payload')->all());
    }

    public function test_a_verified_event_settles_as_before_and_its_stored_body_has_no_secret(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->assertingAcrossTenants();

        // The full paid path: Moyasar's webhook carries `secret_token` = shared-secret, and it is accepted.
        $this->applyAndVerify(['email' => 'paid@example.test']);

        $event = PaymentWebhookEvent::query()->withoutGlobalScopes()->where('verified', true)->sole();
        $this->assertSame(WebhookPayloadRedactor::MASK, $event->payload['secret_token']);
        $this->assertSame('payment_paid', $event->payload['type'], 'the facts are kept');
        $this->assertStringNotContainsString('shared-secret', $this->storedText());
    }

    public function test_a_forged_event_is_still_refused_and_its_guess_is_not_stored_either(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_token' => 'the-real-token']);

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_1', 'type' => 'payment_paid', 'secret_token' => 'a-guess-close-to-the-real-one',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'metadata' => ['secret' => 'nested']],
        ])->assertUnauthorized();

        $event = PaymentWebhookEvent::query()->withoutGlobalScopes()->sole();
        $this->assertFalse((bool) $event->verified);
        $this->assertSame(WebhookPayloadRedactor::MASK, $event->payload['secret_token']);
        $this->assertSame(WebhookPayloadRedactor::MASK, $event->payload['data']['metadata']['secret'], 'at any depth');
        $this->assertStringNotContainsString('a-guess-close', $this->storedText());
        $this->assertStringNotContainsString('nested', $this->storedText());
    }

    public function test_a_body_that_is_not_json_is_kept_as_text_with_the_secret_masked(): void
    {
        $this->assertSame(
            ['raw' => 'id=evt_1&secret_token=[redacted]&type=payment_paid'],
            WebhookPayloadRedactor::forStorage('id=evt_1&secret_token=abc123&type=payment_paid'),
        );
    }

    /** The client-billing path keeps each verified result in `payment_attempts`; that copy carried the body too. */
    private function settledAttempt(): PaymentAttempt
    {
        config()->set('billing.providers.leaky', PayloadCarryingFakeProvider::class);
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $client = ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $billing = app(BillingService::class);
        $invoice = $billing->approveQuote($billing->createQuote(['tenant_id' => $tenant->id, 'client_workspace_id' => $client->id, 'subtotal' => 100, 'total' => 100]));
        $payment = $billing->startPayment($invoice, ['provider' => 'leaky']);

        $event = $billing->handleWebhook('leaky', (string) json_encode([
            'id' => 'evt_leaky', 'type' => 'payment_paid', 'secret_token' => 'client-billing-secret',
            'data' => ['id' => $payment->provider_session_id, 'status' => 'paid'],
        ]), []);
        $this->assertTrue((bool) $event->verified);
        $this->assertSame('paid', $payment->refresh()->status, 'settlement is unchanged');

        return PaymentAttempt::query()->withoutGlobalScopes()->where('payment_id', $payment->id)->latest('id')->firstOrFail();
    }

    public function test_the_payment_attempt_ledger_keeps_the_result_without_the_secret(): void
    {
        $attempt = $this->settledAttempt();

        $this->assertSame(WebhookPayloadRedactor::MASK, $attempt->provider_response['payload']['secret_token']);
        $this->assertStringNotContainsString('client-billing-secret', (string) json_encode(DB::table('payment_attempts')->pluck('provider_response')->all()));
        $this->assertStringNotContainsString('client-billing-secret', $this->storedText());
    }

    public function test_a_secret_straddling_the_cut_of_a_non_json_body_is_masked_whole(): void
    {
        $body = str_repeat('x', 1990).' secret_token=SECRETVALUE12345';

        $this->assertStringNotContainsString('SECRET', (string) WebhookPayloadRedactor::forStorage($body)['raw']);
    }

    public function test_the_cleanup_command_counts_by_default_and_redacts_only_when_told(): void
    {
        $legacy = fn (string $event, array $payload): string => tap((string) Str::uuid(), fn (string $id) => DB::table('payment_webhook_events')->insert([
            'id' => $id, 'provider' => 'moyasar', 'event_id' => $event, 'type' => 'payment_paid', 'verified' => true,
            'payload' => json_encode($payload), 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $old = $legacy('evt_old', ['id' => 'evt_old', 'secret_token' => 'live-secret-value', 'data' => ['id' => 'pay_9', 'amount' => 1000]]);
        $legacy('evt_clean', ['id' => 'evt_clean', 'data' => ['id' => 'pay_8']]);

        // A payment attempt written before the fix, carrying the verified payload whole.
        $attempt = $this->settledAttempt();
        DB::table('payment_attempts')->where('id', $attempt->id)->update(['provider_response' => json_encode(['status' => 'paid', 'payload' => ['secret_token' => 'old-attempt-secret']])]);
        $attempts = fn (): string => (string) json_encode(DB::table('payment_attempts')->pluck('provider_response')->all());
        $events = (int) DB::table('payment_webhook_events')->count();

        $this->artisan('billing:redact-webhook-secrets')
            ->expectsOutputToContain('mode: dry-run')
            ->expectsOutputToContain("payment_webhook_events rows: {$events}")
            ->expectsOutputToContain('payment_webhook_events rows carrying a secret: 1')
            ->expectsOutputToContain('payment_webhook_events rows redacted: 0')
            ->expectsOutputToContain('payment_attempts rows carrying a secret: 1')
            ->expectsOutputToContain('payment_attempts rows redacted: 0')
            ->doesntExpectOutputToContain('live-secret-value')
            ->doesntExpectOutputToContain('old-attempt-secret')
            ->assertSuccessful();
        $this->assertStringContainsString('live-secret-value', $this->storedText(), 'a dry run writes nothing');
        $this->assertStringContainsString('old-attempt-secret', $attempts());

        $this->artisan('billing:redact-webhook-secrets', ['--apply' => true])
            ->expectsOutputToContain('payment_webhook_events rows redacted: 1')
            ->expectsOutputToContain('payment_attempts rows redacted: 1')
            ->assertSuccessful();

        $this->assertStringNotContainsString('live-secret-value', $this->storedText());
        $this->assertStringNotContainsString('old-attempt-secret', $attempts());
        $row = PaymentWebhookEvent::query()->withoutGlobalScopes()->findOrFail($old);
        $this->assertSame(['id' => 'pay_9', 'amount' => 1000], $row->payload['data'], 'nothing else changes');
        $this->assertTrue((bool) $row->verified);

        $this->artisan('billing:redact-webhook-secrets')
            ->expectsOutputToContain('payment_webhook_events rows carrying a secret: 0')
            ->expectsOutputToContain('payment_attempts rows carrying a secret: 0')
            ->assertSuccessful();
    }
}

/** A configured gateway whose verified result carries the body it was sent, as Moyasar's did before the fix. */
final class PayloadCarryingFakeProvider implements PaymentProvider
{
    public function confirmsPayloadIntegrity(): bool
    {
        return true;
    }

    /** @return array{status: string, amount: ?string, currency: ?string, reference: ?string}|null */
    public function fetchPayment(string $providerPaymentId): ?array
    {
        return null;
    }

    public function supportsUnattendedCharge(): bool
    {
        return false;
    }

    /** @param  array<string,mixed>  $payload */
    public function savedPaymentMethodFrom(array $payload): ?array
    {
        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{status: string, provider_payment_id?: string|null, error?: string|null}
     */
    public function chargeStoredMethod(string $token, array $payload): array
    {
        return ['status' => 'unsupported', 'provider_payment_id' => null, 'error' => null];
    }

    public function name(): string
    {
        return 'leaky';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @param  array<string,mixed>  $payload */
    public function createSession(array $payload): array
    {
        return ['status' => 'created', 'session_id' => 'sess_'.($payload['idempotency_key'] ?? 'x'), 'checkout_url' => 'https://pay.test/checkout', 'error' => null];
    }

    /** @param  array<string,mixed>  $payload */
    public function paymentMethodFingerprint(array $payload): ?string
    {
        return null;
    }

    /** @param  array<string,string>  $headers */
    public function verifyWebhook(string $rawBody, array $headers): array
    {
        /** @var array<string,mixed> $body */
        $body = json_decode($rawBody, true) ?: [];

        return [
            'verified' => ($body['secret_token'] ?? null) === 'client-billing-secret',
            'event_id' => (string) ($body['id'] ?? ''),
            'type' => (string) ($body['type'] ?? ''),
            'payment_id' => (string) ($body['data']['id'] ?? ''),
            'status' => (string) ($body['data']['status'] ?? 'processing'),
            'payload' => $body,
        ];
    }
}
