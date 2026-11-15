<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Sending;

use App\Domains\Notifications\Engine\Content\ComposedMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One engine email. Always FROM the verified platform address (`mail.from.address`) — the address the SMTP account is
 * allowed to send as; a workspace only changes the display name and the Reply-To (spec §8, §9). Never a workspace's own
 * domain in From, which the provider would reject or DMARC would fail.
 */
final class EngineMail extends Mailable
{
    public function __construct(public readonly ComposedMessage $message, public readonly string $reference) {}

    public function envelope(): Envelope
    {
        $from = (string) config('mail.from.address');
        $name = $this->message->senderName ?? (string) config('mail.from.name');

        return new Envelope(
            from: new Address($from, $name),
            replyTo: $this->message->replyTo !== null ? [new Address($this->message->replyTo)] : [],
            subject: $this->message->subject,
            tags: ['notifications-v2'],
            metadata: ['reference' => $this->reference],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->message->html);
    }
}
