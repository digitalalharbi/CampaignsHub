<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Services\DigestPresenter;
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
         * `daily` or `weekly` — the rhythm, not a second email.
         *
         * One Mailable and one template serve both because every rule the daily digest follows is a
         * rule the weekly must follow too. A second class would be two places to keep «awareness
         * money never gets a cost per order» true, and the one nobody edits is the one that breaks.
         */
        private readonly string $kind = 'daily',
    ) {}

    public function envelope(): Envelope
    {
        $p = new DigestPresenter($this->lang);
        $ar = $this->lang === 'ar';
        $totals = $this->digest['totals'] ?? [];

        $spend = $p->money($totals['spend'] ?? null);
        $results = number_format((float) ($totals['conversions'] ?? 0));

        $when = $this->kind === 'weekly'
            ? ($ar ? 'هذا الأسبوع' : 'this week')
            : ($ar ? 'أمس' : 'yesterday');

        return new Envelope(
            subject: $ar
                ? config('brand.name')." — {$spend} {$when}، {$results} نتيجة"
                : config('brand.name')." — {$spend} {$when}, {$results} results",
        );
    }

    public function content(): Content
    {
        $p = new DigestPresenter($this->lang);
        $ar = $this->lang === 'ar';
        $app = rtrim((string) config('brand.application_url'), '/');
        $site = rtrim((string) config('brand.frontend_url'), '/');

        return new Content(
            view: 'mail.layout',
            with: [
                'locale' => $this->lang,
                'dir' => $ar ? 'rtl' : 'ltr',
                // Padding and alignment follow the reader's direction; `end` is right in LTR and left
                // in RTL. Email clients do not support logical properties, so it is computed here.
                'endSide' => $ar ? 'left' : 'right',
                'brand' => (string) config('brand.name'),
                'year' => date('Y'),
                'subject' => $this->envelope()->subject,
                'preheader' => $ar
                    ? 'ملخص أمس عبر مشاريعك ومنصاتك.'
                    : 'Yesterday across your projects and platforms.',
                'headerNote' => $this->headerNote($ar),
                'urls' => [
                    'preferences' => $app.'/account/notifications',
                    'privacy' => $site.'/privacy',
                    'terms' => $site.'/terms',
                    'security' => $site.'/security',
                ],
                't' => $this->copy($ar),
                'totals' => [
                    'spend' => $p->money($this->digest['totals']['spend'] ?? null),
                    'conversions' => number_format((float) ($this->digest['totals']['conversions'] ?? 0)),
                    'projects' => (string) ($this->digest['totals']['projects'] ?? 0),
                ],
                'projects' => $this->projects($p, $ar, $app),
                'slot' => view('mail.daily-digest', [
                    't' => $this->copy($ar),
                    'endSide' => $ar ? 'left' : 'right',
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

    /** «الملخص اليومي · 2026-08-06» or «Weekly digest · 2026-08-01 → 2026-08-07». */
    private function headerNote(bool $ar): string
    {
        $from = (string) ($this->digest['date'] ?? '');

        if ($this->kind !== 'weekly') {
            return ($ar ? 'الملخص اليومي · ' : 'Daily digest · ').$from;
        }

        $to = (string) ($this->digest['to_date'] ?? $from);

        return ($ar ? 'الملخص الأسبوعي · ' : 'Weekly digest · ')."{$from} → {$to}";
    }

    /**
     * Each project as the email needs it — strings only.
     *
     * @return list<array<string,mixed>>
     */
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
                'kpis' => [
                    [
                        'label' => $ar ? 'الإنفاق' : 'Spend',
                        'value' => $p->money($totals['spend'] ?? null),
                        'change' => $p->change($change['spend'] ?? null),
                        // Spending more is neither good nor bad on its own, so it is never coloured.
                        'change_colour' => '#8b9a97',
                    ],
                    [
                        'label' => $ar ? 'النتائج' : 'Results',
                        'value' => $p->count($totals, $reported, 'conversions'),
                        'change' => $p->change($change['conversions'] ?? null),
                        'change_colour' => $p->changeColour($change['conversions'] ?? null),
                    ],
                    [
                        'label' => $ar ? 'تكلفة النتيجة' : 'Cost per result',
                        'value' => $p->money($totals['cpa'] ?? null),
                        'change' => $p->change($change['cpa'] ?? null),
                        // A cost improves by falling — the one figure whose arrow inverts.
                        'change_colour' => $p->changeColour($change['cpa'] ?? null, lowerIsBetter: true),
                    ],
                    [
                        'label' => $ar ? 'الظهور' : 'Impressions',
                        'value' => $p->count($totals, $reported, 'impressions'),
                        'change' => $p->change($change['impressions'] ?? null),
                        'change_colour' => '#8b9a97',
                    ],
                ],
                'paths' => $this->paths($p, $ar, $block['paths'] ?? []),
                'best' => $this->named($p, $ar, $block['best_platform'] ?? null, $block['best_campaign'] ?? null),
                'worst' => $this->named($p, $ar, $block['worst_platform'] ?? null, $block['worst_campaign'] ?? null),
                'freshness' => $this->freshness($ar, $block['freshness'] ?? []),
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

        $cost = $ar ? 'تكلفة النتيجة ' : 'cost/result ';

        return $row['label'].' · '.$p->money($row['spend']).' · '.$cost.$p->money($row['cpa']);
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
            'intro' => $this->kind === 'weekly'
                ? 'هذا ما حدث هذا الأسبوع عبر مشاريعك — والقرارات التي تستحق وقتك.'
                : 'هذا ما حدث أمس عبر مشاريعك — والقرارات التي تستحق وقتك اليوم.',
            'account_total' => 'إجمالي الحساب',
            'spend' => 'الإنفاق',
            'results' => 'النتائج',
            'projects' => 'المشاريع',
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
            'intro' => $this->kind === 'weekly'
                ? 'Here is the week across your projects — and what is worth your time.'
                : 'Here is what happened yesterday across your projects — and what is worth your time today.',
            'account_total' => 'Account total',
            'spend' => 'Spend',
            'results' => 'Results',
            'projects' => 'Projects',
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
