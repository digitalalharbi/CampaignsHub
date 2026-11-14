<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Notifications\Providers\MessageProvider;

/** A stand-in for a channel that has credentials. The transport itself is faked separately. */
final class ConfiguredTestEmailProvider implements MessageProvider
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
        return ['status' => 'sent', 'provider_message_id' => 'test', 'error' => null];
    }
}
