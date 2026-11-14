<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Notifications\Providers\MessageProvider;

/**
 * A stand-in for a real, credentialed provider.
 *
 * Wired through `config('providers.channels.email')` rather than by mocking `ProviderRegistry`,
 * because the registry is `final` — and because routing the fake through the real registry is what
 * proves the honest «awaiting credentials» mapping is the thing being exercised.
 */
final class ConfiguredAlertEmailProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @param  array<string,mixed>  $payload */
    public function send(string $destination, array $payload): array
    {
        return ['status' => 'sent', 'provider_message_id' => 'alert-ack', 'error' => null];
    }
}
