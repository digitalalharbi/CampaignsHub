<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Notifications\Providers\MessageProvider;

/** A stand-in for a real, credentialed provider — the registry's own honest mapping is what is tested. */
final class ConfiguredDigestEmailProvider implements MessageProvider
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
        return ['status' => 'sent', 'provider_message_id' => 'digest-ack', 'error' => null];
    }
}
