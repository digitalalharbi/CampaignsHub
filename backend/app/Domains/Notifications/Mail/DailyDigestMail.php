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
        /*
         * BRANDING-HIERARCHY-001 — whose product this email is from.
         *
         * Null means «the platform», which is what an unbranded installation and the mail gallery
         * both want. The AGENCY layer is the right one for a digest: it can span several of that
         * agency's clients, so a client identity would name one of them on a summary about all of
         * them. Resolved by the caller through the same `SharedLinkBranding` the reports use — this
         * class must not grow a second branding engine, and the requirement says so in capitals.
         */
        private readonly ?string $senderName = null,
    ) {}

    /** The name this email presents itself under — the agency's, or the product's. */
    private function brandName(): string
    {
        $name = trim((string) ($this->senderName ?? ''));

        return $name !== '' ? $name : (string) config('brand.name');
    }

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
                ? $this->brandName()." — {$spend} {$when}، {$results} نتيجة"
                : $this->brandName()." — {$spend} {$when}, {$results} results",
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
                'brand' => $this->brandName(),
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
                'totals' => $this->accountCards($p, $ar),
                /*
                  EMAIL-DASHBOARD-UX-001 — the two rows a reader acts on, before the project list.

                  The reference standard the owner supplied leads with one green «best improvement»
                  and one red «sharpest decline», because a person reading on a phone at 8am wants
                  the two ends and not the middle. A list of twelve projects in alphabetical order is
                  a list nobody reads to the bottom.
                */
                'movement' => $this->movement($p, $ar),
                'projects' => $this->projects($p, $ar, $app),
                'slot' => view('mail.daily-digest', [
                    'font' => MailDesign::font($ar),
                    'numericFont' => MailDesign::numericFont(),
                    't' => $this->copy($ar),
                    'endSide' => $ar ? 'left' : 'right',
                    'startSide' => $ar ? 'right' : 'left',
                    'totals' => $this->accountCards($p, $ar),
                    'movement' => $this->movement($p, $ar),
                    'projects' => $this->projects($p, $ar, $app),
                ])->render(),
            ],
        );
    }

    /**
     * What this reader actually subscribed to — «the daily digest», «the weekly digest», «the monthly».
     *
     * The footer told every reader they had chosen the DAILY digest, in an email that might be their
     * weekly or monthly one. It is one line at the bottom, and it is the line that says why this
     * arrived and how to stop it — so a reader who set up a weekly summary and is told they asked for
     * a daily one has been given a reason to distrust the figures above it, and no way to act on the
     * preference they actually hold.
     *
     * One mailable serves three rhythms {@see self::$kind}, which is why this was possible at all: the
     * copy array is built once and knew nothing about the rhythm the header already names.
     */
    private function rhythmName(bool $ar): string
    {
        return match ($this->kind) {
            'weekly' => $ar ? 'الملخص الأسبوعي' : 'weekly digest',
            'monthly' => $ar ? 'الملخص الشهري' : 'monthly digest',
            default => $ar ? 'الملخص اليومي' : 'daily digest',
        };
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
     * The account KPI cards, each with its movement — EMAIL-DASHBOARD-UX-001.
     *
     * Four rather than three, and every one of them carries the change against the previous window
     * of the same length. «41,923 ر.س» tells a reader what was spent and nothing about whether that
     * is the usual amount; the movement is the half that makes it a fact they can act on.
     *
     * Revenue is the fourth card and is DROPPED when nothing reported any — a card reading «0» for a
     * lead-generation account is a measurement of nothing, and four cards where one is always zero
     * teaches a reader to read three.
     *
     * A movement of null renders as no pill at all: every rise from a zero previous window is
     * infinite, and «up ∞%» is not a movement anybody set a threshold on.
     *
     * @return list<array{label: string, value: string, change: string|null, tone: string}>
     */
    private function accountCards(DigestPresenter $p, bool $ar): array
    {
        $totals = $this->digest['totals'] ?? [];
        $change = $totals['change'] ?? [];

        $pill = static function (?float $delta, bool $lowerIsBetter = false): array {
            if ($delta === null) {
                return [null, 'neutral'];
            }

            $arrow = $delta >= 0 ? '▲' : '▼';
            $good = $lowerIsBetter ? $delta < 0 : $delta > 0;

            return [$arrow.' '.number_format(abs($delta) * 100, 1).'%', abs($delta) < 0.005 ? 'neutral' : ($good ? 'good' : 'bad')];
        };

        [$spendChange, $spendTone] = $pill($change['spend'] ?? null);
        [$resultChange, $resultTone] = $pill($change['conversions'] ?? null);
        [$revenueChange, $revenueTone] = $pill($change['revenue'] ?? null);

        $cards = [
            ['label' => $ar ? 'الإنفاق' : 'Spend', 'value' => $p->headline($totals['spend'] ?? null), 'change' => $spendChange, 'tone' => $spendTone],
            ['label' => $ar ? 'النتائج' : 'Results', 'value' => number_format((float) ($totals['conversions'] ?? 0)), 'change' => $resultChange, 'tone' => $resultTone],
            ['label' => $ar ? 'المشاريع' : 'Projects', 'value' => (string) ($totals['projects'] ?? 0), 'change' => null, 'tone' => 'neutral'],
        ];

        if ((float) ($totals['revenue'] ?? 0) > 0) {
            $cards[] = ['label' => $ar ? 'الإيراد' : 'Revenue', 'value' => $p->headline($totals['revenue'] ?? null), 'change' => $revenueChange, 'tone' => $revenueTone];
        }

        return $cards;
    }

    /**
     * The strongest rise and the sharpest fall across the projects — EMAIL-DASHBOARD-UX-001.
     *
     * Movement is measured on RESULTS rather than on spend: spending more is not an improvement, and
     * a digest that celebrates it is one that rewards the wrong behaviour. A project with no
     * previous figure has no movement, not a movement of zero.
     *
     * Both are null when there is only one project with a comparison — «best of one» is a ranking of
     * nothing, and printing it as a highlight is how a reader learns the highlights mean nothing.
     *
     * @return array{best: array{name: string, text: string}|null, worst: array{name: string, text: string}|null}
     */
    private function movement(DigestPresenter $p, bool $ar): array
    {
        $moved = [];

        foreach ($this->digest['projects'] ?? [] as $block) {
            $delta = $block['change']['conversions'] ?? null;

            if ($delta === null) {
                continue;
            }

            $moved[] = ['name' => (string) ($block['project_name'] ?? ''), 'delta' => (float) $delta];
        }

        if (count($moved) < 2) {
            return ['best' => null, 'worst' => null];
        }

        usort($moved, static fn (array $a, array $b): int => $b['delta'] <=> $a['delta']);

        $say = static fn (array $row): array => [
            'name' => $row['name'],
            'text' => ($ar ? 'النتائج ' : 'Results ')
                .($row['delta'] >= 0 ? '▲ ' : '▼ ')
                .number_format(abs($row['delta']) * 100, 1).'%',
        ];

        $best = $moved[0];
        $worst = $moved[count($moved) - 1];

        return [
            // Only a real rise is a rise, and only a real fall is a fall. A «best» that went down is
            // the least bad, and calling it the best is the kind of cheerfulness nobody believes.
            'best' => $best['delta'] > 0 ? $say($best) : null,
            'worst' => $worst['delta'] < 0 ? $say($worst) : null,
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
                  EMAIL-SETTINGS-DEPTH-001 — passed through as the digest produced it.

                  Nothing is re-decided here. `DigestRecommendations` has already applied the
                  approved-only rule, the tenant, the window and the cap; this block builds its view
                  from an explicit whitelist, so a section absent from THIS list renders nowhere no
                  matter how completely it was plumbed underneath.
                */
                'recommendations' => array_values($block['recommendations'] ?? []),
                /*
                  The budget is NOT a section of its own.

                  `ReportObservations` already produces a budget-pace note with the money in it, and
                  a separate row above it said the same thing in fewer words — two statements of one
                  fact, which reads as two problems.
                */
                'notes' => $this->notes($block['observations'] ?? []),
                /*
                  EXECUTIVE-DAILY-DIGEST-001 — what happened after the lead arrived.

                  Counts and rates, and not one name. A digest reaches whatever inbox somebody
                  subscribed with, and lead PII is not mailed by default — so the email says «11
                  never contacted» and the link says who.
                */
                'follow_up' => $this->followUp($p, $ar, $block['follow_up'] ?? null),
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
    /**
     * How complete the figures are, in the reader's terms — CLIENT-DIAGNOSTIC-SEPARATION-001.
     *
     * This printed «آخر مزامنة: 2026-08-18 23:59». A digest goes to whoever subscribed to it,
     * including a client's own management, and a sync clock is a fact about our plumbing: they
     * cannot act on it, cannot ask anyone to move it, and cannot tell from it whether the numbers
     * above are wrong.
     *
     * The fact underneath is still theirs and is still said — a platform missing from the figures
     * is a total that understates — but as a statement about their report rather than about us. An
     * empty string where everything arrived: a line saying «all sources are current» is one a reader
     * learns to skip, including on the day it says something else.
     */
    private function freshness(bool $ar, array $freshness): string
    {
        $failing = array_values(array_filter(
            (array) ($freshness['failing'] ?? []),
            static fn ($row): bool => is_array($row) && ($row['name'] ?? null) !== null,
        ));

        if ($failing === [] && ! (bool) ($freshness['sync_failed'] ?? false)) {
            return '';
        }

        if ($failing === []) {
            return $ar
                ? 'بعض المنصات لم تُرسل بيانات هذه الفترة، وقد تكون الأرقام أقل من الواقع.'
                : 'Some platforms sent no data for this period, so the figures may understate.';
        }

        $names = implode($ar ? '، ' : ', ', array_map(static fn (array $row): string => (string) $row['name'], $failing));

        return $ar
            ? "الأرقام لا تشمل: {$names}."
            : "The figures do not include: {$names}.";
    }

    /**
     * The follow-up section, as rows of label and figure.
     *
     * Three things it deliberately refuses. It prints no NAME — a digest travels to an inbox nobody
     * in this product controls, and the requirement is explicit that raw lead PII is not emailed by
     * default. It prints no rate whose denominator was zero — «0% contacted» out of no leads is a
     * verdict on nothing. And it puts what needs a person FIRST, because a list where every figure
     * carries equal weight is a list nobody acts on.
     *
     * @param  array<string,mixed>|null  $follow
     * @return array{rows: list<array{label: string, value: string}>, attention: list<string>, owners: list<array{name: string, received: string, contacted: string, overdue: string}>}|null
     */
    private function followUp(DigestPresenter $p, bool $ar, ?array $follow): ?array
    {
        if ($follow === null || ((int) ($follow['received'] ?? 0) === 0 && (int) ($follow['overdue'] ?? 0) === 0)) {
            return null;
        }

        $rows = [];

        foreach ([
            'received' => ['وصل', 'Received'],
            'contacted' => ['تم التواصل', 'Contacted'],
            'not_contacted' => ['لم يُتواصل معه', 'Not contacted'],
            'qualified' => ['مؤهَّل', 'Qualified'],
            'appointments' => ['مواعيد', 'Appointments'],
            'won' => ['مكسوب', 'Won'],
        ] as $key => $labels) {
            $rows[] = [
                'label' => $ar ? $labels[0] : $labels[1],
                'value' => number_format((float) ($follow[$key] ?? 0)),
            ];
        }

        /*
         * A rate whose denominator was zero comes back null from the workspace, and stays absent
         * here rather than becoming «0%». They are different statements and only one is true.
         */
        foreach ([
            'contact_rate' => ['نسبة التواصل', 'Contact rate'],
            'qualification_rate' => ['نسبة التأهيل', 'Qualification rate'],
        ] as $key => $labels) {
            if (($follow[$key] ?? null) !== null) {
                $rows[] = ['label' => $ar ? $labels[0] : $labels[1], 'value' => $p->percent($follow[$key], 0)];
            }
        }

        $median = $follow['first_response']['median_minutes'] ?? null;

        if ($median !== null) {
            $rows[] = [
                'label' => $ar ? 'وسيط زمن أول رد' : 'Median first response',
                'value' => number_format((float) $median).($ar ? ' دقيقة' : ' min'),
            ];
        }

        $attention = [];

        foreach ($follow['attention'] ?? [] as $item) {
            $n = number_format((float) ($item['count'] ?? 0));
            $attention[] = match ($item['kind'] ?? '') {
                'unassigned_leads' => $ar ? $n.' عميل محتمل بلا مسؤول' : $n.' lead(s) with no owner',
                'overdue_follow_up' => $ar ? $n.' متابعة متأخرة' : $n.' overdue follow-up(s)',
                'never_contacted' => $ar ? $n.' لم يُتواصل معه بعد' : $n.' never contacted',
                default => '',
            };
        }

        /*
         * The team, only when there IS a team.
         *
         * One owner is not a comparison, and «unassigned» alone is already said above under what
         * needs a person — printing it again as a one-row league table says the same thing twice.
         */
        $owners = array_values(array_filter(
            $follow['by_owner'] ?? [],
            static fn (array $row): bool => ($row['owner_name'] ?? null) !== null,
        ));

        return [
            'rows' => $rows,
            'attention' => array_values(array_filter($attention)),
            'owners' => count($owners) < 2 ? [] : array_map(static fn (array $row): array => [
                'name' => (string) $row['owner_name'],
                'received' => number_format((float) ($row['received'] ?? 0)),
                'contacted' => number_format((float) ($row['contacted'] ?? 0)),
                'overdue' => number_format((float) ($row['overdue'] ?? 0)),
            ], $owners),
        ];
    }

    /** @return array<string,string> */
    private function copy(bool $ar): array
    {
        return $ar ? [
            'greeting' => "صباح الخير، {$this->recipientName}",
            /*
             * The window this mail is ABOUT, named — the footer's defect one line higher up.
             *
             * This was a two-way branch: daily said «أمس», and everything else said «هذا الأسبوع».
             * So the MONTHLY digest opened «هذا ما حدث هذا الأسبوع» under a header reading
             * «الملخص الشهري · 2026-08-01 → 2026-08-31» — the first sentence contradicting the line
             * above it. Found by rendering the third rhythm; the first two happened to be right,
             * which is why reading only them missed it.
             */
            'intro' => match ($this->kind) {
                'monthly' => 'هذا ما حدث هذا الشهر عبر مشاريعك — والقرارات التي تستحق وقتك.',
                'weekly' => 'هذا ما حدث هذا الأسبوع عبر مشاريعك — والقرارات التي تستحق وقتك.',
                default => 'هذا ما حدث أمس عبر مشاريعك — والقرارات التي تستحق وقتك اليوم.',
            },
            'account_total' => 'إجمالي الحساب',
            'spend' => 'الإنفاق',
            'results' => 'النتائج',
            'projects' => 'المشاريع',
            'funnel' => 'المسار',
            'content' => 'المحتوى',
            'recommendations' => 'التوصيات المعتمدة',
            'best_content' => 'الأفضل',
            'declining' => 'يتراجع',
            'fatigued' => 'يحتاج تجديدًا',
            'budget' => 'الميزانية',
            'notes' => 'ما يستحق الانتباه',
            'movement' => 'أبرز التحرّكات',
            'best_move' => 'أفضل تحسّن',
            'worst_move' => 'أكبر تراجع',
            'follow_up' => 'متابعة العملاء المحتملين',
            'by_owner' => 'حسب المسؤول',
            'owner' => 'المسؤول',
            'received_short' => 'وصل',
            'contacted_short' => 'تم التواصل',
            'overdue_short' => 'متأخر',
            'no_blended_note' => 'لا تُجمَع تكلفة النتيجة ولا العائد عبر المشاريع — ذلك يقسم أموال عميل على نتائج عميل آخر. تجدها داخل كل مشروع، وبحسب المسار.',
            'by_path' => 'حسب المسار التسويقي',
            'best' => 'الأفضل',
            'worst' => 'الأضعف',
            'open_dashboard' => 'افتح لوحة التحكم',
            'footer_note' => 'تصلك هذه الرسالة لأنك اخترت '.$this->rhythmName(true).'. يمكنك تغيير المشاريع أو التوقيت أو إيقافها من تفضيلات الإشعارات.',
            'manage_preferences' => 'تفضيلات الإشعارات',
            'privacy' => 'الخصوصية',
            'terms' => 'الشروط',
            'security' => 'الأمان',
        ] : [
            'greeting' => "Good morning, {$this->recipientName}",
            'intro' => match ($this->kind) {
                'monthly' => 'Here is the month across your projects — and what is worth your time.',
                'weekly' => 'Here is the week across your projects — and what is worth your time.',
                default => 'Here is what happened yesterday across your projects — and what is worth your time today.',
            },
            'account_total' => 'Account total',
            'spend' => 'Spend',
            'results' => 'Results',
            'projects' => 'Projects',
            'funnel' => 'Funnel',
            'content' => 'Content',
            'recommendations' => 'Approved recommendations',
            'best_content' => 'Best',
            'declining' => 'Slipping',
            'fatigued' => 'Needs refreshing',
            'budget' => 'Budget',
            'notes' => 'Worth your attention',
            'movement' => 'Biggest moves',
            'best_move' => 'best improvement',
            'worst_move' => 'sharpest decline',
            'follow_up' => 'Lead follow-up',
            'by_owner' => 'By owner',
            'owner' => 'Owner',
            'received_short' => 'Received',
            'contacted_short' => 'Contacted',
            'overdue_short' => 'Overdue',
            'no_blended_note' => 'Cost per result and return are not summed across projects — that would divide one client’s money by another client’s results. They appear inside each project, by marketing path.',
            'by_path' => 'By marketing path',
            'best' => 'Best',
            'worst' => 'Weakest',
            'open_dashboard' => 'Open the dashboard',
            'footer_note' => 'You are receiving this because you chose the '.$this->rhythmName(false).'. Change the projects, the time, or turn it off in your notification preferences.',
            'manage_preferences' => 'Notification preferences',
            'privacy' => 'Privacy',
            'terms' => 'Terms',
            'security' => 'Security',
        ];
    }
}
