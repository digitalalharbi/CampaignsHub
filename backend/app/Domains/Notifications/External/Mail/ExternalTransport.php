<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Mail;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Sends one prepared external message with an EXPLICIT SMTP envelope (spec §9: every address its own copy, its own
 * envelope). The headers may name the primary recipient and the visible CCs while the envelope names only the one
 * address this copy is for — which is how a BCC copy reaches its reader without ever appearing in a header.
 *
 * Uses the configured mailer's own Symfony transport (SMTP in production, `array` in the test suite), so no second mail
 * stack exists. Returns the provider's message id; throws on a transport refusal (the caller classifies it).
 */
class ExternalTransport
{
    public function send(Email $email, Envelope $envelope): ?string
    {
        $mailer = Mail::mailer();
        $sent = $mailer->getSymfonyTransport()->send($email, $envelope);

        return $sent?->getMessageId();
    }
}
