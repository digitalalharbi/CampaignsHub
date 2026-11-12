<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Mail\Concerns\OffersUnsubscribe;
use App\Domains\Notifications\Support\AlertVisual;
use App\Domains\Notifications\Support\MailShell;
use App\Domains\Notifications\Support\MailSubject;
use App\Support\Frontend;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * One message, one thing to say — MAIL-006.
 *
 * ## Why one mailable rather than eight
 *
 * An alert, a report that is ready, an invoice, an approval request and a new message are the same
 * shape: a headline, a sentence of what it means, and one way to act on it. Eight templates would be
 * eight places for the identity to drift and eight places to fix a rendering bug found in Outlook.
 *
 * What differs between them is the WORDS, and the words come from the caller — which is also what
 * keeps this honest: this class states nothing about the figures, so it cannot state anything wrong
 * about them.
 *
 * ## `$lang`, not `$locale`
 *
 * `Illuminate\Mail\Mailable` already declares a non-readonly `$locale`; promoting a readonly
 * property of that name is a fatal error at class-load time. The same trap as `DailyDigestMail`.
 */
final class OperationalMail extends Mailable
{
    use OffersUnsubscribe, Queueable, SerializesModels;

    public function __construct(
        /** `alert` · `report_ready` · `billing` · `approval` · `request` · `message`. */
        public readonly string $kind,
        /** `critical` · `warning` · `positive` · `info` — decides the colour and nothing else. */
        public readonly string $severity,
        public readonly string $title,
        public readonly string $detail,
        /** Which project, invoice or conversation this is about. Shown under the title. */
        public readonly string $context,
        public readonly string $lang = 'ar',
        public readonly string $recipientName = '',
        /** Where the one button goes. Relative to the app, resolved at render. */
        public readonly string $path = '/app/dashboard',
        /** What the button says — a verb, never «اضغط هنا». */
        public readonly ?string $action = null,
        /** Why this arrived, when «you follow this project» is not true — an account or billing message. */
        public readonly ?string $why = null,
        /** DIGESTS-CADENCE-BUDGETS-001 — the next steps in order («افتح ← راجع ← قرّر»); none when the button says it all. */
        public readonly ?string $steps = null,
        /** DIGESTS-CADENCE-BUDGETS-001 — the problem drawn (`AlertMailItem::viz()` shapes); the message's stages when null. */
        public readonly ?array $viz = null,
    ) {}

    private function firstLine(): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $this->detail) ?: []), static fn (string $l): bool => $l !== ''));

        return $lines[0] ?? '';
    }

    /** @return list<string> the detail's other lines, each short, at most four */
    private function otherLines(): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $this->detail) ?: []), static fn (string $l): bool => $l !== ''));

        return array_map(static fn (string $l): string => Str::limit($l, AlertVisual::LINE_LIMIT, '…'), array_slice($lines, 1, 4));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: MailSubject::of($this->title, $this->lang === 'ar'));
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $app = Frontend::origin();

        /*
         * Rendered through the shared shell, exactly as the digest is.
         *
         * The body is composed into `slot` rather than extending the layout, because the layout is
         * where the brand header, the policy footer and the unsubscribe live — and an operational
         * message that lost the unsubscribe would be the one a person reports as spam.
         */
        $body = MailShell::build(
            lang: $this->lang,
            subject: $this->title,
            // The line an inbox shows beside the subject. It is the DETAIL, because the subject
            // already carries the title and repeating it wastes the only preview a reader gets.
            preheader: $this->detail,
        ) + [
            /*
             * DIGESTS-CADENCE-BUDGETS-001 — the same card as every alert: chip, the message drawn, ONE line, ordered steps, one
             * button. A detail written on several lines keeps its other lines as a short list under the card, never a paragraph.
             */
            'card' => AlertVisual::present([
                'severity' => $this->severity,
                'title' => $this->title,
                'context' => $this->context,
                'detail' => $this->firstLine(),
                'action' => (string) $this->steps,
                'rule' => $this->kind,
                'viz' => $this->viz,
                'url' => null,
            ], $ar),
            'more' => $this->otherLines(),
            'severity' => $this->severity,
            'title' => $this->title,
            'detail' => $this->detail,
            'context' => $this->context,
            'greeting' => $ar
                ? ($this->recipientName !== '' ? "مرحبًا، {$this->recipientName}" : 'مرحبًا')
                : ($this->recipientName !== '' ? "Hello, {$this->recipientName}" : 'Hello'),
            'actionLabel' => $this->action ?? ($ar ? 'فتح في كامبينز هب' : 'Open in CampaignsHub'),
            'actionUrl' => $app.$this->path,
        ];
        if ($this->why !== null) {
            $body['t']['why'] = $this->why;
        }

        return new Content(view: 'mail.layout', with: $body + [
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'slot' => view('mail.operational', $body)->render(),
        ]);
    }
}
