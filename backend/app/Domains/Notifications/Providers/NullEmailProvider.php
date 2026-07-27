<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Providers;

/**
 * Placeholder email adapter used until real SMTP/API credentials are wired. It NEVER sends and NEVER reports
 * success — callers record `awaiting_provider_credentials`. Swapping in a real provider is a container binding.
 */
final class NullEmailProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    /** @param  array<string,mixed>  $payload */
    public function send(string $destination, array $payload): array
    {
        return ['status' => 'awaiting_credentials', 'provider_message_id' => null, 'error' => null];
    }
}
