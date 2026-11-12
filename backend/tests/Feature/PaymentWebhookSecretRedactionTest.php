<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Billing\Support\WebhookPayloadRedactor;
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

    public function test_the_cleanup_command_counts_by_default_and_redacts_only_when_told(): void
    {
        $legacy = fn (string $event, array $payload): string => tap((string) Str::uuid(), fn (string $id) => DB::table('payment_webhook_events')->insert([
            'id' => $id, 'provider' => 'moyasar', 'event_id' => $event, 'type' => 'payment_paid', 'verified' => true,
            'payload' => json_encode($payload), 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $old = $legacy('evt_old', ['id' => 'evt_old', 'secret_token' => 'live-secret-value', 'data' => ['id' => 'pay_9', 'amount' => 1000]]);
        $legacy('evt_clean', ['id' => 'evt_clean', 'data' => ['id' => 'pay_8']]);

        $this->artisan('billing:redact-webhook-secrets')
            ->expectsOutputToContain('mode: dry-run')
            ->expectsOutputToContain('rows: 2')
            ->expectsOutputToContain('rows carrying a secret: 1')
            ->expectsOutputToContain('rows redacted: 0')
            ->doesntExpectOutputToContain('live-secret-value')
            ->assertSuccessful();
        $this->assertStringContainsString('live-secret-value', $this->storedText(), 'a dry run writes nothing');

        $this->artisan('billing:redact-webhook-secrets', ['--apply' => true])
            ->expectsOutputToContain('rows redacted: 1')
            ->assertSuccessful();

        $this->assertStringNotContainsString('live-secret-value', $this->storedText());
        $row = PaymentWebhookEvent::query()->withoutGlobalScopes()->findOrFail($old);
        $this->assertSame(['id' => 'pay_9', 'amount' => 1000], $row->payload['data'], 'nothing else changes');
        $this->assertTrue((bool) $row->verified);

        $this->artisan('billing:redact-webhook-secrets')->expectsOutputToContain('rows carrying a secret: 0')->assertSuccessful();
    }
}
