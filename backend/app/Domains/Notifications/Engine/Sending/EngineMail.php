<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Sending;

use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Mail\Concerns\OffersUnsubscribe;
use App\Domains\Notifications\Support\MailShell;
use App\Domains\Notifications\Support\MailSubject;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One engine email, inside the platform's own mail shell (logo band, fonts, direction, footer) so it meets the same
 * contract as every other message (`EmailSystemGuardTest`).
 *
 * Always FROM the verified platform address (`mail.from.address`) — the address the SMTP account may send as; a
 * workspace changes only the display name and the Reply-To (spec §8, §9). Never a workspace's own domain in From.
 */
final class EngineMail extends Mailable
{
    use OffersUnsubscribe;

    public function __construct(public readonly ComposedMessage $message, public readonly string $reference, public readonly string $lang = 'ar') {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->message->senderName ?? (string) config('mail.from.name')),
            replyTo: $this->message->replyTo !== null ? [new Address($this->message->replyTo)] : [],
            subject: MailSubject::of($this->message->subject, $this->lang === 'ar'),
            tags: ['notifications-v2'],
            metadata: ['reference' => $this->reference],
        );
    }

    public function content(): Content
    {
        $shell = MailShell::build(lang: $this->lang, subject: $this->message->subject, preheader: $this->message->body, preferences: false);

        return new Content(view: 'mail.layout', with: $shell + [
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'slot' => $this->message->html,
        ]);
    }
}
