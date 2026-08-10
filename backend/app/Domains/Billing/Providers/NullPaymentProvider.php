<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

/**
 * Placeholder payment adapter used until a real gateway's credentials are wired. It NEVER opens a real session,
 * NEVER verifies a webhook, and can NEVER move money — callers record `awaiting_credentials`. Swapping in a
 * real provider is a single container/config binding; no call site changes.
 */
final class NullPaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    /** @param  array<string,mixed>  $payload */
    public function createSession(array $payload): array
    {
        return ['status' => 'awaiting_credentials', 'session_id' => null, 'checkout_url' => null, 'error' => null];
    }

    /** @param  array<string,string>  $headers */
    public function verifyWebhook(string $rawBody, array $headers): array
    {
        return ['verified' => false];
    }

    /**
     * Vacuously true: this adapter verifies nothing, so no event of its ever reaches settlement.
     *
     * Answering false would be worse than meaningless — it would send a caller off to `fetchPayment()`
     * on a provider that has no gateway behind it at all.
     */
    public function confirmsPayloadIntegrity(): bool
    {
        return true;
    }

    /** @return array{status: string, amount: ?string, currency: ?string, reference: ?string}|null */
    public function fetchPayment(string $providerPaymentId): ?array
    {
        return null;
    }

    /** @param  array<string,mixed>  $payload */
    public function paymentMethodFingerprint(array $payload): ?string
    {
        // No gateway, no payment method, nothing to fingerprint.
        return null;
    }
}
