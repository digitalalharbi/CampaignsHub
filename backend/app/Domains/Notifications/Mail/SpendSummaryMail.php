<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Mail\Concerns\OffersUnsubscribe;
use App\Domains\Notifications\Services\DigestPresenter;
use App\Domains\Notifications\Support\AlertEventText;
use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Notifications\Support\MailDesign;
use App\Domains\Notifications\Support\MailDisclaimer;
use App\Domains\Notifications\Support\MailLinks;
use App\Domains\Notifications\Support\MailShell;
use App\Domains\Notifications\Support\MailSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — «ملخص الإنفاق»: the period's spend and what is left of each project's spend limit.
 *
 * Two readers, one mail. The workspace reads it with one button into the budgets. A recipient OUTSIDE the platform (`$external`)
 * reads a self-contained message: the standalone shell (no link into the product, no login), no button, only the unsubscribe —
 * and only the projects bound to that recipient, at the project level (no campaign is ever named).
 *
 * `$lang`, not `$locale`: `Mailable` already declares a non-readonly `$locale`.
 */
final class SpendSummaryMail extends Mailable
{
    use OffersUnsubscribe, Queueable, SerializesModels;

    /**
     * @param  array<string,mixed>  $summary  `ProjectSpendSummary::build()`
     * @param  string  $kind  daily | weekly | monthly — the period the spend covers
     */
    public function __construct(
        public readonly array $summary,
        public readonly string $lang = 'ar',
        public readonly string $kind = 'weekly',
        public readonly bool $external = false,
        public readonly string $recipientName = '',
    ) {}

    public function title(bool $ar): string
    {
        return ($ar ? 'ملخص الإنفاق' : 'Spend summary').' • '.DigestSchedule::periodWord($this->kind, $ar);
    }

    public function envelope(): Envelope
    {
        $ar = $this->lang === 'ar';
        $near = (int) ($this->summary['total']['near'] ?? 0);
        $tail = $near > 0 ? ($ar ? ": {$near} قرّبت تخلص" : ": {$near} close to the limit") : '';

        return new Envelope(subject: MailSubject::of($this->title($ar).$tail, $ar));
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $s = $this->summary;
        $t = $s['total'];
        $p = static fn (?string $currency): DigestPresenter => new DigestPresenter($ar ? 'ar' : 'en', (int) ($s['days'] ?? 7), $currency);
        $noData = $ar ? 'لا بيانات' : 'No data';
        $notConverted = $ar ? 'غير محوّل' : 'Not converted';

        $projects = array_map(function (array $r) use ($ar, $p, $notConverted): array {
            $stateLabel = match ($r['state']) {
                'over' => $ar ? 'تجاوزت الحد' : 'Over the limit',
                'near' => $ar ? 'قرّبت تخلص' : 'Close to the limit',
                'ok' => $ar ? 'ضمن الحد' : 'Within the limit',
                'unconverted' => $notConverted,
                default => $ar ? 'بدون حد' : 'No limit',
            };
            $cap = $p($r['cap_currency']);
            $used = $r['used'] !== null ? (int) round((float) $r['used'] * 100) : null;
            $remainingPct = $used !== null ? max(0, 100 - $used) : null;

            return $r + [
                'state_label' => $stateLabel,
                'spend_text' => $r['spend_withheld'] ? $notConverted : $p($r['spend_currency'])->money($r['spend']),
                'cap_text' => $r['cap'] !== null ? $cap->money($r['cap']) : '',
                'consumed_text' => $r['consumed'] !== null ? $cap->money($r['consumed']) : '',
                'remaining_text' => $r['remaining'] !== null ? $cap->money($r['remaining']).($remainingPct !== null ? ' · '.AlertEventText::ltr($remainingPct.'%') : '') : '',
                'bar' => $used === null ? 0 : max(2, min(100, $used)),
                'days_text' => $r['days_left'] === null ? '' : ($ar
                    ? 'يكفي تقريبًا '.AlertEventText::ltr((string) $r['days_left']).' '.((int) $r['days_left'] === 1 ? 'يوم' : ((int) $r['days_left'] === 2 ? 'يومين' : 'أيام')).' بمعدل الصرف الحالي'
                    : 'About '.$r['days_left'].' '.((int) $r['days_left'] === 1 ? 'day' : 'days').' left at the current pace'),
            ];
        }, (array) ($s['projects'] ?? []));

        $fmt = [
            'total_spend' => $t['spend'] !== null ? $p($t['spend_currency'])->headline($t['spend']) : $noData,
            'total_remaining' => $t['remaining'] !== null ? $p($t['cap_currency'])->headline($t['remaining']) : $noData,
        ];

        $periodLabel = DigestSchedule::periodLabel($this->kind, (string) $s['from'], (string) $s['to'], $ar);
        $subject = $this->envelope()->subject;
        $shell = MailShell::build(
            lang: $this->lang,
            subject: (string) $subject,
            preheader: trim($periodLabel.' · '.($ar ? 'الإنفاق ' : 'Spend ').$fmt['total_spend'].' · '.($ar ? 'المتبقي ' : 'Remaining ').$fmt['total_remaining']),
            preferences: ! $this->external,
            footerNote: MailDisclaimer::note($this->lang),
        );

        return new Content(view: 'mail.layout', with: $shell + [
            'unsubscribeUrl' => $this->unsubscribeUrl,
            // A recipient outside the platform gets the standalone shell: no link into the product, only the unsubscribe.
            'standalone' => $this->external,
            'slot' => view('mail.spend-summary', [
                's' => ['projects' => $projects] + $s,
                'fmt' => $fmt,
                'title' => $this->title($ar),
                'periodLabel' => $periodLabel,
                'recipientLine' => $this->external && $this->recipientName !== '' ? ($ar ? "مرحبًا، {$this->recipientName}" : "Hello, {$this->recipientName}") : '',
                'external' => $this->external,
                'budgetsUrl' => MailLinks::to('/app/budgets'),
                'dir' => $ar ? 'rtl' : 'ltr',
                'startSide' => $ar ? 'right' : 'left',
                'endSide' => $ar ? 'left' : 'right',
                'font' => MailDesign::font($ar),
            ])->render(),
        ]);
    }
}
