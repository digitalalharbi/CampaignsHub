<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Services\DigestPresenter;
use App\Domains\Notifications\Support\MailDesign;
use App\Domains\Notifications\Support\MailLinks;
use App\Support\AdPlatforms;
use App\Support\Frontend;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The daily digest, as an email — MAIL-002.
 *
 * ## Everything is decided here, nothing in the template
 *
 * The Blade file receives strings. Whether a metric exists, whether a change is comparable, what
 * colour an arrow is: all of it is settled by {@see DigestPresenter} before the view is touched,
 * because `{{ $m['reach'] ?? 0 }}` is one keystroke and it prints a measured zero for a metric no
 * platform sent. In an inbox that is a false alarm the reader cannot check.
 *
 * ## The subject line carries the answer
 *
 * «CampaignsHub — 12,400 SAR, 84 نتيجة» tells somebody on a lock screen whether to open it. A
 * subject that says only «Your daily report» makes every day look identical, which is how a daily
 * email stops being read.
 */
final class DailyDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $digest  the payload from `DailyDigest::build()`
     */
    public function __construct(
        private readonly array $digest,
        /*
         * `$lang`, not `$locale`.
         *
         * `Illuminate\Mail\Mailable` already declares a non-readonly `$locale`, and a readonly
         * redeclaration is a fatal error at class-load time — the kind that takes the whole queue
         * worker down rather than one message.
         */
        private readonly string $lang,
        private readonly string $recipientName,
        /*
         * `daily`, `weekly` or `monthly` — the rhythm, not three emails.
         *
         * One Mailable and one template serve all three because every rule the daily digest follows
         * is a rule the others must follow too. Three classes would be three places to keep
         * «awareness money never gets a cost per order» true, and the one nobody edits is the one
         * that breaks.
         */
        private readonly string $kind = 'daily',
    ) {}

    public function envelope(): Envelope
    {
        $p = new DigestPresenter($this->lang, (int) ($this->digest['days'] ?? 1));
        $ar = $this->lang === 'ar';
        $totals = $this->digest['totals'] ?? [];

        $spend = $p->money($totals['spend'] ?? null);
        $results = number_format((float) ($totals['conversions'] ?? 0));

        // The subject names the period it covers. A monthly report labelled «أمس» is a monthly
        // report nobody opens, because the subject line says it is yesterday's.
        $when = match ($this->kind) {
            'monthly' => $ar ? 'هذا الشهر' : 'this month',
            'weekly' => $ar ? 'هذا الأسبوع' : 'this week',
            // EMAIL-DAILY-WINDOW-001 — the daily email reports the last seven days, so «أمس» in the
            // subject would name a span it does not cover. The rhythm is daily; the period is a week.
            default => $ar ? 'آخر 7 أيام' : 'the last 7 days',
        };

        return new Envelope(
            subject: $ar
                ? config('brand.name')." — {$spend} {$when}، {$results} نتيجة"
                : config('brand.name')." — {$spend} {$when}, {$results} results",
        );
    }

    public function content(): Content
    {
        $p = new DigestPresenter($this->lang, (int) ($this->digest['days'] ?? 1));
        $ar = $this->lang === 'ar';
        $app = rtrim((string) config('brand.application_url'), '/');
        $site = Frontend::origin();

        return new Content(
            view: 'mail.layout',
            with: [
                // MAIL-DS-001 — one stack per language, Arabic faces first when the mail is Arabic.
                'font' => MailDesign::font($ar),
                'numericFont' => MailDesign::numericFont(),
                'locale' => $this->lang,
                'dir' => $ar ? 'rtl' : 'ltr',
                // Padding and alignment follow the reader's direction; `end` is right in LTR and left
                // in RTL. Email clients do not support logical properties, so it is computed here.
                'endSide' => $ar ? 'left' : 'right',
                // The side a border sits on. Logical properties do not exist in Outlook, so the
                // direction is resolved once here rather than guessed at in the template.
                'startSide' => $ar ? 'right' : 'left',
                'brand' => (string) config('brand.name'),
                'year' => date('Y'),
                'subject' => $this->envelope()->subject,
                'preheader' => $ar
                    ? 'ملخص أمس عبر مشاريعك ومنصاتك.'
                    : 'Yesterday across your projects and platforms.',
                'headerNote' => $this->headerNote($ar),
                // One place, because the two mailables already disagreed about the unsubscribe —
                // and the one that was wrong resolved anyway, through a redirect (MAIL-008).
                'urls' => MailLinks::footer(),
                't' => $this->copy($ar),
                'totals' => [
                    'spend' => $p->money($this->digest['totals']['spend'] ?? null),
                    'conversions' => number_format((float) ($this->digest['totals']['conversions'] ?? 0)),
                    'projects' => (string) ($this->digest['totals']['projects'] ?? 0),
                ],
                'projects' => $this->projects($p, $ar, $app),
                'slot' => view('mail.daily-digest', [
                    'font' => MailDesign::font($ar),
                    'numericFont' => MailDesign::numericFont(),
                    't' => $this->copy($ar),
                    'endSide' => $ar ? 'left' : 'right',
                    'startSide' => $ar ? 'right' : 'left',
                    'totals' => [
                        'spend' => $p->money($this->digest['totals']['spend'] ?? null),
                        'conversions' => number_format((float) ($this->digest['totals']['conversions'] ?? 0)),
                        'projects' => (string) ($this->digest['totals']['projects'] ?? 0),
                    ],
                    'projects' => $this->projects($p, $ar, $app),
                ])->render(),
            ],
        );
    }

    /** «الملخص اليومي · 2026-08-06», «Weekly digest · 2026-08-01 → 2026-08-07», or the month. */
    private function headerNote(bool $ar): string
    {
        $from = (string) ($this->digest['date'] ?? '');

        if ($this->kind === 'daily') {
            return ($ar ? 'الملخص اليومي · ' : 'Daily digest · ').$from;
        }

        $to = (string) ($this->digest['to_date'] ?? $from);

        $label = $this->kind === 'monthly'
            ? ($ar ? 'الملخص الشهري · ' : 'Monthly digest · ')
            : ($ar ? 'الملخص الأسبوعي · ' : 'Weekly digest · ');

        return $label."{$from} → {$to}";
    }

    /**
     * Each project as the email needs it — strings only.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * The funnel, as far as it was actually reported — MAIL-005.
     *
     * `count` is null for a stage no platform sent (FUNNEL-NULL-001), and those stages are DROPPED
     * rather than drawn at zero: a bar of length nothing in an email reads as a step where everybody
     * left, which is the opposite of «nobody measured this».
     *
     * @param  list<array<string,mixed>>  $stages
     * @return list<array<string,mixed>>
     */
    private function funnel(DigestPresenter $p, bool $ar, array $stages): array
    {
        /*
         * The stage names in the reader's own language.
         *
         * `MetricsAggregator::funnel()` labels in English — it is the engine, and the client has
         * `funnelStageLabel()` for the same job. An Arabic email that printed «Landing Page View»
         * beside «الظهور» is half-translated, which reads worse than either language alone.
         */
        $labels = [
            'impressions' => 'الظهور',
            'clicks' => 'النقرات',
            'landing_page_views' => 'زيارات الصفحة',
            'add_to_cart' => 'الإضافة للسلة',
            'checkout' => 'بدء الدفع',
            'purchases' => 'الشراء',
        ];

        $reported = array_values(array_filter($stages, static fn (array $s): bool => is_numeric($s['count'] ?? null)));
        if (count($reported) < 2) {
            return [];
        }

        $top = max(1.0, (float) $reported[0]['count']);

        return array_map(static fn (array $s): array => [
            'label' => $ar ? ($labels[$s['stage'] ?? ''] ?? (string) ($s['label'] ?? '')) : (string) ($s['label'] ?? ''),
            'count' => number_format((float) $s['count']),
            // A percentage of the widest stage, so the bars mean something without an axis.
            'width' => (int) round(((float) $s['count'] / $top) * 100),
            'step' => is_numeric($s['step_rate'] ?? null) ? $p->percent((float) $s['step_rate'], 1) : null,
        ], $reported);
    }

    /**
     * Best content and what is slipping — never a heading with nothing under it.
     *
     * @param  array<string,mixed>|null  $creatives
     * @return array<string,mixed>|null
     */
    private function creatives(?array $creatives): ?array
    {
        if ($creatives === null) {
            return null;
        }

        return [
            'best' => $creatives['best'] ?? null,
            'declining' => array_slice((array) ($creatives['declining'] ?? []), 0, 2),
            'fatigued' => array_slice((array) ($creatives['fatigued'] ?? []), 0, 2),
        ];
    }

    /**
     * The notes, most serious first, capped at what a reader will actually read.
     *
     * Three. A fourth is not read, and an email that lists ten alerts every morning teaches its
     * reader that the alerts do not mean anything — which costs more than the notes were worth.
     *
     * @param  list<array<string,mixed>>  $observations
     * @return list<array<string,mixed>>
     */
    private function notes(array $observations): array
    {
        $tone = ['critical' => 'bad', 'warning' => 'warn', 'positive' => 'good', 'info' => 'neutral'];

        return array_map(static fn (array $o): array => [
            'title' => (string) ($o['title'] ?? ''),
            'detail' => (string) ($o['detail'] ?? ''),
            'tone' => $tone[$o['severity'] ?? 'info'] ?? 'neutral',
        ], array_slice($observations, 0, 3));
    }

    private function projects(DigestPresenter $p, bool $ar, string $app): array
    {
        $out = [];

        foreach ($this->digest['projects'] ?? [] as $block) {
            $totals = $block['totals'] ?? [];
            $reported = $block['reported'] ?? [];
            $change = $block['change'] ?? [];

            $out[] = [
                'name' => (string) ($block['project_name'] ?? ''),
                'verdict' => $p->verdict($block),
                /*
                  The cards this project's money is judged on — MAIL-005, §14.6 in an inbox.

                  They were spend, results, cost-per-result and impressions on every project. On a
                  brand project the third is spend divided by whatever events happened to be
                  reported: an arithmetic accident printed in bold, in a medium where nobody can
                  click through to check it.
                */
                'kpis' => $p->cards($block),
                'paths' => $this->paths($p, $ar, $block['paths'] ?? []),
                'best' => $this->named($p, $ar, $block['best_platform'] ?? null, $block['best_campaign'] ?? null),
                'worst' => $this->named($p, $ar, $block['worst_platform'] ?? null, $block['worst_campaign'] ?? null),
                'freshness' => $this->freshness($ar, $block['freshness'] ?? []),
                // The funnel, the content and the notes — the three sections that turn a list of
                // figures into something a reader can act on without opening the product.
                'funnel' => $this->funnel($p, $ar, $block['funnel'] ?? []),
                'creatives' => $this->creatives($block['creatives'] ?? null),
                /*
                  The budget is NOT a section of its own.

                  `ReportObservations` already produces a budget-pace note with the money in it, and
                  a separate row above it said the same thing in fewer words — two statements of one
                  fact, which reads as two problems.
                */
                'notes' => $this->notes($block['observations'] ?? []),
                'url' => $app.'/app/dashboard',
            ];
        }

        return $out;
    }

    /**
     * The marketing paths that actually carried money.
     *
     * A path with no spend is left out rather than printed as a zero row: it is not a result, it is
     * a kind of campaign this account did not run yesterday.
     *
     * @param  array<string,mixed>  $paths
     * @return list<array<string,string>>
     */
    private function paths(DigestPresenter $p, bool $ar, array $paths): array
    {
        $out = [];

        foreach ($paths as $key => $bucket) {
            if ((float) ($bucket['spend'] ?? 0) <= 0) {
                continue;
            }

            $out[] = [
                'label' => $p->pathLabel((string) $key),
                'spend' => $p->money($bucket['spend']),
                // Only the conversion path has a cost per result. The others report what they DID
                // buy — campaigns running — rather than borrowing a denominator they do not own.
                'result' => $bucket['cost_per_result'] !== null
                    ? ($ar ? 'تكلفة النتيجة ' : 'cost/result ').$p->money($bucket['cost_per_result'])
                    : ($ar ? $bucket['campaigns'].' حملة' : $bucket['campaigns'].' campaigns'),
            ];
        }

        return $out;
    }

    /**
     * «Meta · 3,900 SAR · cost 46 SAR», or nothing at all.
     *
     * Nothing when no row had a cost per result to be ranked by: a campaign that produced no
     * conversions has no cost per result, and calling it «the worst» compares it on a figure that
     * does not exist. It belongs in the attention list, which the verdict already covers.
     *
     * @param  array<string,mixed>|null  $platform
     * @param  array<string,mixed>|null  $campaign
     */
    private function named(DigestPresenter $p, bool $ar, ?array $platform, ?array $campaign): ?string
    {
        $row = $platform ?? $campaign;

        if ($row === null) {
            return null;
        }

        // A PLATFORM is named in the reader's language; a CAMPAIGN keeps the name its operator gave
        // it. «تيك توك» is a translation, «Meta — Retargeting» is a proper noun (MAIL-007).
        $label = $platform !== null
            ? AdPlatforms::name((string) $row['label'], $ar ? 'ar' : 'en')
            : (string) $row['label'];

        $cost = $ar ? 'تكلفة النتيجة ' : 'cost/result ';

        return $label.' · '.$p->money($row['spend']).' · '.$cost.$p->money($row['cpa']);
    }

    /** @param  array<string,mixed>  $freshness */
    private function freshness(bool $ar, array $freshness): string
    {
        $at = $freshness['last_sync_at'] ?? null;
        $when = $at === null ? ($ar ? 'لم تتم بعد' : 'not yet') : substr((string) $at, 0, 16);

        return ($ar ? 'آخر مزامنة: ' : 'Last sync: ').$when;
    }

    /** @return array<string,string> */
    private function copy(bool $ar): array
    {
        return $ar ? [
            'greeting' => "صباح الخير، {$this->recipientName}",
            'intro' => $this->kind !== 'daily'
                ? 'هذا ما حدث هذا الأسبوع عبر مشاريعك — والقرارات التي تستحق وقتك.'
                : 'هذا ما حدث أمس عبر مشاريعك — والقرارات التي تستحق وقتك اليوم.',
            'account_total' => 'إجمالي الحساب',
            'spend' => 'الإنفاق',
            'results' => 'النتائج',
            'projects' => 'المشاريع',
            'funnel' => 'المسار',
            'content' => 'المحتوى',
            'best_content' => 'الأفضل',
            'declining' => 'يتراجع',
            'fatigued' => 'يحتاج تجديدًا',
            'budget' => 'الميزانية',
            'notes' => 'ما يستحق الانتباه',
            'no_blended_note' => 'لا تُجمَع تكلفة النتيجة ولا العائد عبر المشاريع — ذلك يقسم أموال عميل على نتائج عميل آخر. تجدها داخل كل مشروع، وبحسب المسار.',
            'by_path' => 'حسب المسار التسويقي',
            'best' => 'الأفضل',
            'worst' => 'الأضعف',
            'open_dashboard' => 'افتح لوحة التحكم',
            'footer_note' => 'تصلك هذه الرسالة لأنك اخترت الملخص اليومي. يمكنك تغيير المشاريع أو التوقيت أو إيقافها من تفضيلات الإشعارات.',
            'manage_preferences' => 'تفضيلات الإشعارات',
            'privacy' => 'الخصوصية',
            'terms' => 'الشروط',
            'security' => 'الأمان',
        ] : [
            'greeting' => "Good morning, {$this->recipientName}",
            'intro' => $this->kind !== 'daily'
                ? 'Here is the week across your projects — and what is worth your time.'
                : 'Here is what happened yesterday across your projects — and what is worth your time today.',
            'account_total' => 'Account total',
            'spend' => 'Spend',
            'results' => 'Results',
            'projects' => 'Projects',
            'funnel' => 'Funnel',
            'content' => 'Content',
            'best_content' => 'Best',
            'declining' => 'Slipping',
            'fatigued' => 'Needs refreshing',
            'budget' => 'Budget',
            'notes' => 'Worth your attention',
            'no_blended_note' => 'Cost per result and return are not summed across projects — that would divide one client’s money by another client’s results. They appear inside each project, by marketing path.',
            'by_path' => 'By marketing path',
            'best' => 'Best',
            'worst' => 'Weakest',
            'open_dashboard' => 'Open the dashboard',
            'footer_note' => 'You are receiving this because you chose the daily digest. Change the projects, the time, or turn it off in your notification preferences.',
            'manage_preferences' => 'Notification preferences',
            'privacy' => 'Privacy',
            'terms' => 'Terms',
            'security' => 'Security',
        ];
    }
}
