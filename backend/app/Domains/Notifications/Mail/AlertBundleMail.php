<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Support\MailShell;
use App\Support\Frontend;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Everything one sweep found, in one message — MAIL-013.
 *
 * ## Why this replaced one email per finding
 *
 * `AlertDispatcher` mailed each observation separately. A morning where a budget is running ahead on
 * two clients, a sync has stopped and a cost per result is climbing produced four emails inside the
 * same second — and four emails arriving together is not four times the attention, it is a filter
 * rule. The dedup and the three-day cooldown were already careful about repeating a finding; nothing
 * was careful about the volume of DIFFERENT findings.
 *
 * Aggregating is also the honest shape for what the sweep is: it runs four times a day and reports
 * what it found. That is a bulletin, not four interruptions.
 *
 * ## The subject counts, because the inbox is the first screen
 *
 * «٣ تنبيهات تحتاج قرارًا» tells somebody whether to open it now. A subject built from the first
 * finding would hide the other two behind a headline about a campaign they may not care about.
 *
 * ## `$lang`, not `$locale`
 *
 * `Illuminate\Mail\Mailable` already declares a non-readonly `$locale`; promoting a readonly property
 * of that name is a fatal error at class-load time. The same trap as `DailyDigestMail`.
 */
final class AlertBundleMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{severity: string, title: string, detail: string, context: string}>  $items
     *                                                                                                ordered as the sweep found them: project by project, and inside a project as
     *                                                                                                `ReportObservations` ranked them, so the most serious is first
     */
    public function __construct(
        public readonly array $items,
        public readonly string $lang = 'ar',
        public readonly string $recipientName = '',
    ) {}

    public function envelope(): Envelope
    {
        $ar = $this->lang === 'ar';
        $n = count($this->items);

        if ($n === 1) {
            return new Envelope(subject: sprintf('CampaignsHub — %s', $this->items[0]['title']));
        }

        return new Envelope(subject: $ar
            // Arabic counts 3–10 with a plural and 11+ with a singular. Getting this wrong is two
            // mistakes in three characters, which MAIL-007 recorded the first time.
            ? sprintf('CampaignsHub — %d %s تحتاج قرارًا', $n, $n <= 10 ? 'تنبيهات' : 'تنبيهًا')
            : sprintf('CampaignsHub — %d alerts need a decision', $n));
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $n = count($this->items);

        $body = MailShell::build(
            lang: $this->lang,
            subject: $ar
                ? ($n === 1 ? 'تنبيه يحتاج قرارًا' : sprintf('%d %s تحتاج قرارًا', $n, $n <= 10 ? 'تنبيهات' : 'تنبيهًا'))
                : ($n === 1 ? 'An alert needs a decision' : sprintf('%d alerts need a decision', $n)),
            // The preheader lists what is inside rather than repeating the count the subject carries.
            preheader: implode(' · ', array_map(
                static fn (array $item): string => $item['title'],
                array_slice($this->items, 0, 3),
            )),
        ) + [
            'items' => $this->items,
            'greeting' => $ar
                ? ($this->recipientName !== '' ? "مرحبًا، {$this->recipientName}" : 'مرحبًا')
                : ($this->recipientName !== '' ? "Hello, {$this->recipientName}" : 'Hello'),
            'intro' => $ar
                ? 'هذه الملاحظات ظهرت في آخر فحص، وكل واحدة منها تستحق قرارًا اليوم.'
                : 'These came up in the last sweep, and each is worth a decision today.',
            'actionLabel' => $ar ? 'فتح في CampaignsHub' : 'Open in CampaignsHub',
            'actionUrl' => Frontend::origin().'/app/dashboard',
        ];

        return new Content(view: 'mail.layout', with: $body + [
            'slot' => view('mail.alerts', $body)->render(),
        ]);
    }
}
