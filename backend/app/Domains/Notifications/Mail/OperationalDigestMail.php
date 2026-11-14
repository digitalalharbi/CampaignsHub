<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Mail\Concerns\OffersUnsubscribe;
use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Notifications\Support\MailDesign;
use App\Domains\Notifications\Support\MailDisclaimer;
use App\Domains\Notifications\Support\MailShell;
use App\Domains\Notifications\Support\MailSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * EMAIL-DIGESTS-OPERATIONAL-001 — the operational digest: ONE project, or ALL of a reader's projects.
 *
 * The Owner's brief: a reader should not need to log into a platform, or even the system, to know where
 * things stand. A project digest therefore carries the report link's own logic — an overall summary and
 * a block per connected platform (spend, results, return by the project's objective, with the change
 * against the previous period, a bar for the shape of the window, the budget pace where one exists),
 * the conversion funnel, the best and weakest content and a short list of what to do, each with a
 * button into the exact page of the system. The portfolio digest is the same blocks across every
 * project, ordered by what needs attention.
 *
 * Same shell as every other message (header, footer, unsubscribe, RTL, mobile). No script, no SVG, no
 * CSS grid: tables, inline styles and bars made of cells, so it draws in Gmail, Outlook and Apple Mail.
 * The only images are the brand banner in the shell and content covers served from our own origin.
 *
 * `$lang`, not `$locale`: `Mailable` already declares a non-readonly `$locale`.
 */
final class OperationalDigestMail extends Mailable
{
    use OffersUnsubscribe, Queueable, SerializesModels;

    /**
     * @param  array<string,mixed>  $model  `OperationalDigestModel::project()` or `::portfolio()`
     * @param  string  $scope  `project` | `portfolio`
     * @param  string  $kind  `daily` | `weekly` | `monthly`
     */
    public function __construct(
        public readonly array $model,
        public readonly string $lang = 'ar',
        public readonly string $recipientName = '',
        public readonly string $kind = 'daily',
        public readonly string $scope = 'portfolio',
        public readonly ?string $periodLabel = null,
    ) {}

    private function cadence(bool $ar): string
    {
        return DigestSchedule::periodWord($this->kind, $ar);
    }

    public function envelope(): Envelope
    {
        $ar = $this->lang === 'ar';
        $name = $this->scope === 'project' ? (string) $this->model['name'] : '';
        /*
         * DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — «ملخص المشاريع • أمس» / «• الأسبوع الماضي» / «• الشهر الماضي», and the one
         * project's «ملخص المشروع • أمس»: the period word of each rhythm, then its dates in the mail's head.
         */
        $title = DigestSchedule::title($this->scope === 'project' ? 'project' : 'portfolio', $this->kind, $ar);
        $tail = $this->scope === 'project'
            ? ($this->model['status']['label'] ?? '')
            : ($ar ? ($this->model['need_attention'] > 0 ? "{$this->model['need_attention']} تحتاج قرار" : 'ما فيه شيء يحتاج قرار') : ($this->model['need_attention'] > 0 ? "{$this->model['need_attention']} need a decision" : 'nothing needs a decision'));

        return new Envelope(subject: MailSubject::of(trim(($name !== '' ? "{$name} — " : '').$title.($tail !== '' ? ": {$tail}" : '')), $ar));
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $project = $this->scope === 'project';
        $m = $this->model;

        // DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — the preheader leads with PERFORMANCE: the period, the spend and its change; never sync counts.
        $spend = $project ? ($m['kpis'][0] ?? null) : ($m['kpis'][0] ?? null);
        $preheader = trim(implode(' · ', array_filter([
            (string) ($this->periodLabel ?? ''),
            $spend !== null ? trim(($spend['label'] ?? '').' '.($spend['value'] ?? '').' '.($spend['change'] ?? '')) : '',
            $project ? (string) ($m['status']['reason'] ?? '') : '',
        ])));

        $shell = MailShell::build(lang: $this->lang, subject: $this->envelope()->subject, preheader: $preheader, footerNote: MailDisclaimer::note($this->lang, $this->copy($ar)['unit_note']));
        $t = $this->copy($ar);
        $shell['t'] = array_merge($shell['t'], $t);

        return new Content(view: 'mail.layout', with: $shell + [
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'slot' => view($project ? 'mail.digest.project' : 'mail.digest.portfolio', [
                'm' => $m,
                'kind' => $this->kind,
                'cadence' => $this->cadence($ar),
                'greeting' => $ar ? ($this->recipientName !== '' ? "مرحبًا، {$this->recipientName}" : 'مرحبًا') : ($this->recipientName !== '' ? "Hello, {$this->recipientName}" : 'Hello'),
                'periodLabel' => $this->periodLabel,
                'dir' => $ar ? 'rtl' : 'ltr',
                'startSide' => $ar ? 'right' : 'left',
                'endSide' => $ar ? 'left' : 'right',
                'font' => MailDesign::font($ar),
                't' => $t,
                'tone' => ['good' => '#0d8a6f', 'warn' => '#b45309', 'bad' => '#b91c1c', 'neutral' => '#5b6b68'],
                'toneBg' => ['good' => '#e8f5f1', 'warn' => '#fffbeb', 'bad' => '#fef2f2', 'neutral' => '#faf8f3'],
            ])->render(),
        ]);
    }

    /** @return array<string,string> */
    private function copy(bool $ar): array
    {
        $kindNote = DigestSchedule::comparedWith($this->kind, $ar);

        return $ar ? [
            'kind_note' => $kindNote,
            'needs' => 'ما يحتاج قرارًا',
            'nothing' => 'لا شيء يحتاج قرارًا الآن.',
            'summary' => 'الملخص العام',
            'platforms' => 'المنصات',
            'funnel' => 'من الظهور إلى النتيجة',
            'content' => 'المحتوى',
            'top' => 'الأفضل أداءً',
            'weak' => 'يحتاج تجديدًا',
            'changed' => 'ما الذي تغيّر',
            'health' => 'حالة المشاريع',
            'moves' => 'أبرز التحركات',
            'best_move' => 'أفضل تحسّن',
            'worst_move' => 'أكبر تراجع',
            'mix' => 'توزيع الإنفاق على المنصات',
            'projects' => 'المشاريع',
            'open_report' => 'افتح التقرير',
            'open_platforms' => 'افتح المنصات داخل النظام',
            'open_attention' => 'عرض الحملات التي تحتاج انتباهًا',
            'open_content' => 'افتح أداء المحتوى',
            'open_project' => 'افتح ملخص المشروع',
            'open_alerts' => 'افتح التنبيهات داخل النظام',
            'no_platforms' => 'لا توجد منصات مربوطة بأرقام في هذه الفترة.',
            'why' => 'وصلتك لأنك فعّلت الملخصات. تبقى عملة كل حساب الأصلية محفوظة في النظام.',
            'unit_note' => 'المبالغ بعملة العرض الموحدة؛ «لا بيانات» تعني أن الرقم لم يصل أو لا يمكن حسابه، وليس صفرًا.',
            'space' => 'مساحة الإنفاق اليومي',
            'follow' => 'متابعة العملاء',
            'sync_note' => 'بعض المصادر ما تحدّثت، فالأرقام ممكن تكون ناقصة.',
            'linking' => 'ملخص الربط',
        ] : [
            'kind_note' => $kindNote,
            'needs' => 'Needs a decision',
            'nothing' => 'Nothing needs a decision right now.',
            'summary' => 'Overall summary',
            'platforms' => 'Platforms',
            'funnel' => 'From impression to result',
            'content' => 'Content',
            'top' => 'Best performing',
            'weak' => 'Needs refreshing',
            'changed' => 'What changed',
            'health' => 'Project health',
            'moves' => 'Notable moves',
            'best_move' => 'Best improvement',
            'worst_move' => 'Biggest decline',
            'mix' => 'Spend by platform',
            'projects' => 'Projects',
            'open_report' => 'Open the report',
            'open_platforms' => 'Open the platforms in the system',
            'open_attention' => 'See the campaigns that need attention',
            'open_content' => 'Open content performance',
            'open_project' => 'Open the project summary',
            'open_alerts' => 'Open the alerts in the system',
            'no_platforms' => 'No connected platform sent figures for this period.',
            'why' => 'Sent because you turned on summaries. Each account keeps its original currency in the system.',
            'unit_note' => 'Amounts are in the unified display currency; «No data» means the figure did not arrive or cannot be computed — it is not zero.',
            'space' => 'Daily spend',
            'follow' => 'Lead follow-up',
            'sync_note' => 'Some sources have not refreshed, so figures may be incomplete.',
            'linking' => 'Connections summary',
        ];
    }
}
