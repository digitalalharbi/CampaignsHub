<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Mail\Concerns\OffersUnsubscribe;
use App\Domains\Notifications\Support\MailDesign;
use App\Domains\Notifications\Support\MailShell;
use App\Domains\Notifications\Support\MailSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * PLATFORM-INBOX-ATTENTION-001 — the platform's answer to somebody who wrote to it from the website.
 *
 * Not `OperationalMail`: that one says «something happened, here is where to look» and trims its detail to a
 * line or two. A reply IS the message — the administrator's words, whole, in the order they wrote them — so this
 * template sets the body as paragraphs and nothing competes with it. There is no button: a visitor has no
 * account to open, and a link they did not ask for in a reply to their own question is what phishing looks like.
 *
 * The body is the administrator's plain text and is escaped like every other value; line breaks are kept by
 * splitting into paragraphs, never by rendering HTML that somebody typed.
 */
final class PlatformReplyMail extends Mailable
{
    use OffersUnsubscribe, Queueable, SerializesModels;

    public function __construct(
        /** What the visitor wrote about — their subject, a ticket reference, a request reference. */
        public readonly string $about,
        public readonly string $body,
        public readonly string $lang = 'ar',
        public readonly string $recipientName = '',
        /** The reference the visitor was given, when there is one. */
        public readonly ?string $reference = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: MailSubject::of($this->title(), $this->lang === 'ar'));
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $tone = MailDesign::tone('info');
        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\R{2,}/u', $this->body) ?: []),
            static fn (string $p): bool => $p !== '',
        ));

        $body = MailShell::build(
            lang: $this->lang,
            subject: $this->title(),
            preheader: mb_substr((string) ($paragraphs[0] ?? ''), 0, 120),
        ) + [
            'title' => $this->title(),
            'about' => $this->about,
            'reference' => $this->reference,
            'referenceLabel' => $ar ? 'المرجع' : 'Reference',
            'paragraphs' => array_map(static fn (string $p): array => preg_split('/\R/u', $p) ?: [$p], $paragraphs),
            'toneInk' => $tone['ink'],
            'toneBg' => $tone['bg'],
            'greeting' => $ar
                ? ($this->recipientName !== '' ? "مرحبًا، {$this->recipientName}" : 'مرحبًا')
                : ($this->recipientName !== '' ? "Hello, {$this->recipientName}" : 'Hello'),
            'signature' => $ar ? 'فريق كامبينز هَب' : 'The CampaignsHub team',
            'replyHint' => $ar
                ? 'للمتابعة، رد على هذه الرسالة أو تواصل معنا من صفحة «تواصل معنا» في الموقع.'
                : 'To follow up, reply to this email or reach us from the contact page on the site.',
        ];
        $body['t']['why'] = $ar ? 'وصلتك لأنك راسلت كامبينز هَب من الموقع.' : 'Sent because you wrote to CampaignsHub from the website.';

        return new Content(view: 'mail.layout', with: $body + [
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'slot' => view('mail.platform-reply', $body)->render(),
        ]);
    }

    private function title(): string
    {
        return $this->lang === 'ar' ? 'رد على رسالتك' : 'A reply to your message';
    }
}
