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
}
