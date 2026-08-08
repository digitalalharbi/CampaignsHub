<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Support\MailDesign;
use App\Domains\Notifications\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
    use Queueable, SerializesModels;

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
    ) {}

    public function envelope(): Envelope
    {
        $ar = $this->lang === 'ar';

        return new Envelope(
            subject: $ar
                ? sprintf('CampaignsHub — %s', $this->title)
                : sprintf('CampaignsHub — %s', $this->title),
        );
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $app = rtrim((string) config('brand.frontend_url', 'https://campaignshub.io'), '/');

        /*
         * Rendered through the shared shell, exactly as the digest is.
         *
         * The body is composed into `slot` rather than extending the layout, because the layout is
         * where the brand header, the policy footer and the unsubscribe live — and an operational
         * message that lost the unsubscribe would be the one a person reports as spam.
         */
        $body = [
            // The design system's own values — MAIL-DS-001. The Arabic stack leads with faces that
            // can actually render Arabic, which the shared Latin-first stack never could.
            'font' => MailDesign::font($ar),
            'numericFont' => MailDesign::numericFont(),
            'locale' => $this->lang,
            'dir' => $ar ? 'rtl' : 'ltr',
            'startSide' => $ar ? 'right' : 'left',
            'brand' => 'CampaignsHub',
            'headerNote' => $ar
                ? 'مساعدك لمتابعة كل حملاتك الإعلانية المدفوعة من مكان واحد'
                : 'Your assistant for every paid campaign, in one place',
            'subject' => $this->title,
            // The line an inbox shows beside the subject. It is the DETAIL, because the subject
            // already carries the title and repeating it wastes the only preview a reader gets.
            'preheader' => $this->detail,
            'severity' => $this->severity,
            'title' => $this->title,
            'detail' => $this->detail,
            'context' => $this->context,
            'greeting' => $ar
                ? ($this->recipientName !== '' ? "مرحبًا، {$this->recipientName}" : 'مرحبًا')
                : ($this->recipientName !== '' ? "Hello, {$this->recipientName}" : 'Hello'),
            'actionLabel' => $this->action ?? ($ar ? 'فتح في CampaignsHub' : 'Open in CampaignsHub'),
            'actionUrl' => $app.$this->path,
            // One place — see `MailLinks`. This class had the unsubscribe pointing at a path that
            // only worked because the router happened to redirect it.
            'urls' => MailLinks::footer(),
            't' => $ar
                ? ['manage_preferences' => 'إدارة إشعاراتك', 'privacy' => 'الخصوصية', 'terms' => 'الشروط', 'security' => 'الأمان',
                    'why' => 'وصلتك هذه الرسالة لأنك تتابع هذا المشروع في CampaignsHub.']
                : ['manage_preferences' => 'Manage your notifications', 'privacy' => 'Privacy', 'terms' => 'Terms', 'security' => 'Security',
                    'why' => 'You are receiving this because you follow this project in CampaignsHub.'],
        ];

        return new Content(view: 'mail.layout', with: $body + [
            'year' => date('Y'),
            'slot' => view('mail.operational', $body)->render(),
        ]);
    }
}
