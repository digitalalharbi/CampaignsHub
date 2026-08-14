<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Providers;

use App\Domains\Subscriptions\Notifications\MailTransportState;

/**
 * Marks the built-in Laravel mailer as the live email channel once SMTP/API credentials are present.
 *
 * Transactional mail is still composed and sent by TransactionalMailer through Laravel's Mail facade;
 * this adapter is the readiness gate shared with the delivery ledger.
 */
final class LaravelMailProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return MailTransportState::isLive();
    }

    /** @param  array<string,mixed>  $payload */
    public function send(string $destination, array $payload): array
    {
        return [
            'status' => $this->isConfigured() ? 'sent' : 'awaiting_credentials',
            'provider_message_id' => null,
            'error' => null,
        ];
    }
}
