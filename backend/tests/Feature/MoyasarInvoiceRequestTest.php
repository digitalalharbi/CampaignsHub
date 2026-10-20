<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Providers\MoyasarPaymentProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MOYASAR-REDIRECT-001 — the invoice request says what Moyasar's reference says: the customer is sent
 * back by `success_url` and `back_url`, `callback_url` is a server notification and is not pointed at a
 * front-end page, the amount is in the smallest unit, and our reference rides in the metadata.
 */
final class MoyasarInvoiceRequestTest extends TestCase
{
    public function test_the_invoice_returns_the_customer_by_success_and_back_url_not_callback_url(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_token' => 'tok']);
        Http::fake(['api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_1', 'url' => 'https://checkout.moyasar.com/invoices/inv_1'], 201)]);

        $out = (new MoyasarPaymentProvider)->createSession([
            'amount' => '49.00', 'currency' => 'USD', 'description' => 'Plan', 'reference' => 'ref-1', 'return_url' => 'https://campaignshub.io/signup/status',
        ]);

        $this->assertSame('created', $out['status']);
        $this->assertSame('https://checkout.moyasar.com/invoices/inv_1', $out['checkout_url']);

        Http::assertSent(function (Request $r): bool {
            $b = $r->data();

            return $r->url() === 'https://api.moyasar.com/v1/invoices'
                && $r->isJson()
                && $b['amount'] === 4900
                && $b['success_url'] === 'https://campaignshub.io/signup/status'
                && $b['back_url'] === 'https://campaignshub.io/signup/status'
                && ! array_key_exists('callback_url', $b)
                && $b['metadata']['reference'] === 'ref-1';
        });
    }

    public function test_without_credentials_nothing_is_sent(): void
    {
        Http::fake();

        $this->assertSame('awaiting_credentials', (new MoyasarPaymentProvider)->createSession(['amount' => '9.00'])['status']);
        Http::assertNothingSent();
    }
}
