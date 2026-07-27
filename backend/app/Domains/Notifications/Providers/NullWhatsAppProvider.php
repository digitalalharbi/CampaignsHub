<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Providers;

/**
 * Placeholder WhatsApp adapter used until a real Business API provider is wired. Never sends, never reports
 * success — callers record `awaiting_provider_credentials`.
 */
final class NullWhatsAppProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'whatsapp';
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
