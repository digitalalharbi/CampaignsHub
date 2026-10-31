<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PAY-WEBHOOK-REFUSAL-001 — the payment webhook says «refused» to what it refuses.
 *
 * Production, 2026-10-30: an empty POST to `/payments/webhook/moyasar` answered 200 «Webhook received», and the same body sent again
 * answered with an «unexpected error» — the unverified trace row collided with itself on `event_id`. A forged event must be answered
 * 401 every time, still leave its one trace, and never surface a database error; an unknown gateway is 404, not a crash.
 */
final class PaymentWebhookRefusalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_forged_event_is_refused_every_time_and_leaves_one_trace(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_token' => 'the-real-token']);
        $forged = ['id' => 'evt_1', 'type' => 'payment_paid', 'secret_token' => 'guess', 'data' => ['id' => 'pay_1', 'status' => 'paid']];

        $this->postJson('/api/v1/payments/webhook/moyasar', $forged)->assertUnauthorized()->assertJsonPath('success', false);
        $this->postJson('/api/v1/payments/webhook/moyasar', $forged)->assertUnauthorized();

        $this->assertSame(1, PaymentWebhookEvent::query()->count());
        $this->assertFalse((bool) PaymentWebhookEvent::query()->sole()->verified);
    }

    public function test_an_empty_body_with_no_gateway_configured_is_refused_not_received(): void
    {
        $this->postJson('/api/v1/payments/webhook/moyasar', [])->assertUnauthorized();
        $this->postJson('/api/v1/payments/webhook/moyasar', [])->assertUnauthorized();

        $this->assertSame(1, PaymentWebhookEvent::query()->count());
    }

    public function test_an_unknown_gateway_is_not_found_and_records_nothing(): void
    {
        $this->postJson('/api/v1/payments/webhook/not-a-gateway', ['id' => 'x'])->assertNotFound();

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }
}
