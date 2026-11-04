<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Mail\Concerns\OffersUnsubscribe;
use App\Domains\Notifications\Support\MailDesign;
use App\Domains\Notifications\Support\MailShell;
use App\Domains\Notifications\Support\MailSubject;
use App\Domains\Reports\Support\ScheduledReportFigures;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A scheduled report has arrived — EMAIL-GAPS (§17.3.2, §17.4.2).
 *
 * A decision report, not analytics: results, the content that worked, the content worth improving, and
 * one button. It takes only figures and names already made client-safe by
 * {@see ScheduledReportFigures}, so there is no spend or campaign name here
 * for an audience setting to leak. Every recipient reads the same safe body; the money is behind the button.
 */
final class ScheduledReportMail extends Mailable
{
    use OffersUnsubscribe, Queueable, SerializesModels;

    /**
     * @param  list<array{label: string, value: string, unit: ?string, up: ?string}>  $cards  absent figures are absent
     */
    public function __construct(
        public readonly string $reportTitle,
        public readonly string $context,
        public readonly string $period,
        public readonly array $cards,
        public readonly ?string $bestContent,
        public readonly ?string $improveContent,
        public readonly string $url,
        public readonly string $lang = 'ar',
        public readonly string $recipientName = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: MailSubject::of($this->reportTitle));
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';

        $body = MailShell::build(
            lang: $this->lang,
            subject: $this->reportTitle,
            preheader: $ar ? "نتائج {$this->context} للفترة {$this->period}" : "Results for {$this->context}, {$this->period}",
        ) + [
            'title' => $this->reportTitle,
            'context' => $this->context,
            'period' => $this->period,
            'cards' => $this->cards,
            'bestContent' => $this->bestContent,
            'improveContent' => $this->improveContent,
            'actionUrl' => $this->url,
            'actionLabel' => $ar ? 'فتح التقرير' : 'Open the report',
            'positive' => MailDesign::tone('positive'),
            'greeting' => $this->recipientName !== ''
                ? ($ar ? "مرحبًا، {$this->recipientName}" : "Hello, {$this->recipientName}")
                : ($ar ? 'مرحبًا' : 'Hello'),
            'w' => $ar
                ? ['results' => 'النتائج في هذه الفترة', 'best' => 'أفضل محتوى', 'improve' => 'فرصة تحسين']
                : ['results' => 'Results for this period', 'best' => 'Best content', 'improve' => 'Worth improving'],
        ];
        $body['t']['why'] = $ar
            ? 'وصلتك هذه الرسالة لأنك ضمن مستلمي هذا التقرير المجدول.'
            : 'You are receiving this because you are on the recipient list of this scheduled report.';

        return new Content(view: 'mail.layout', with: $body + [
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'slot' => view('mail.scheduled-report', $body)->render(),
        ]);
    }
}
