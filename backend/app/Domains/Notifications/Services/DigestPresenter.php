<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Campaigns\Enums\MarketingPath;

/**
 * The digest payload, turned into the sentences an email can print — MAIL-002.
 *
 * ## Why the formatting lives here and not in the template
 *
 * A Blade file is the worst place to decide whether a number exists. `{{ $m['reach'] ?? 0 }}` is one
 * keystroke, it reads as defensive, and it prints a measured zero for a metric no platform sent —
 * which in an inbox is a false alarm the reader cannot check. So the template receives STRINGS that
 * are already decided, and there is no branch left in it to get wrong.
 *
 * ## Latin digits in both languages
 *
 * The product's standing rule. «١٢٬٤٠٠ ريال» beside an English platform name is unreadable, and a
 * screenshot of the Arabic email has to be comparable with the English one.
 *
 * ## Every block answers four questions
 *
 * What happened, why it matters, what changed, what to do. `verdict()` is where the third and fourth
 * are written — a digest that only reports figures is a dashboard delivered by email, which is worse
 * than the dashboard because it cannot be filtered or sorted.
 */
final class DigestPresenter
{
    /** Metrics whose absence must be stated rather than shown as zero. */
    private const COUNTS = ['impressions', 'clicks', 'conversions', 'purchases', 'leads', 'reach', 'landing_page_views'];

    public function __construct(private readonly string $locale = 'ar') {}

    private function ar(): bool
    {
        return $this->locale === 'ar';
    }

    /** Money, always with its currency — a bare number reads as a count. */
    public function money(float|int|null $value, string $currency = 'SAR'): string
    {
        if ($value === null) {
            return $this->noData();
        }

        return number_format((float) $value, ((float) $value) === floor((float) $value) ? 0 : 2).' '.$currency;
    }

    /** A count, or the honest reason there is not one. */
    public function count(array $totals, array $reported, string $key): string
    {
        // Checked BEFORE the value, because the value is a coalesced zero in exactly this case.
        if (in_array($key, self::COUNTS, true) && ($reported[$key] ?? true) === false) {
            return $this->notProvided();
        }

        $value = $totals[$key] ?? null;

        return $value === null ? $this->noData() : number_format((float) $value);
    }

    /** A ratio like ROAS. Null means the denominator was missing, which is not «zero return». */
    public function ratio(float|int|null $value, string $suffix = '×'): string
    {
        return $value === null ? $this->noData() : number_format((float) $value, 2).$suffix;
    }

    /** A percentage. `0.0215` → «2.15%». */
    public function percent(float|int|null $value, int $digits = 2): string
    {
        return $value === null ? $this->noData() : number_format((float) $value * 100, $digits).'%';
    }

    /**
     * A day-over-day change, as text with its direction.
     *
     * Returns null — not «0%» — when there is nothing to compare. A change against an absence is a
     * sentence about a number that was never there, and beside a figure it reads as fact.
     */
    public function change(?float $delta): ?string
    {
        if ($delta === null) {
            return null;
        }

        $arrow = $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '=');

        return $arrow.' '.number_format(abs($delta) * 100, 0).'%';
    }

    /** Green for good, red for bad, grey for neither — costs invert. */
    public function changeColour(?float $delta, bool $lowerIsBetter = false): string
    {
        if ($delta === null || abs($delta) < 0.005) {
            return '#8b9a97';
        }

        $good = $lowerIsBetter ? $delta < 0 : $delta > 0;

        return $good ? '#0f766e' : '#b91c1c';
    }

    public function pathLabel(string $path): string
    {
        $case = MarketingPath::tryFrom($path);

        /*
         * An unknown key is shown AS the key, never relabelled.
         *
         * This fell back to Awareness, so any key the enum did not recognise was quietly presented
         * as brand spend — money labelled as something it is not, in the one section of the digest
         * whose entire purpose is keeping the paths apart. A caller passing a bad key has a bug, and
         * the email should make that visible rather than paper over it with a plausible word.
         *
         * Found when a preview fixture keyed this map as a list: every row rendered «الوعي», and the
         * conversion path's spend appeared under the awareness label.
         */
        return $case?->labels()[$this->ar() ? 'ar' : 'en'] ?? $path;
    }

    /**
     * The sentence that makes the block worth reading — what changed, and what to do about it.
     *
     * Deliberately conservative. A verdict offered on thin evidence is worse than none: the reader
     * acts on it once, finds it was noise, and stops reading the email. So «nothing needs you today»
     * is a real and frequent answer, and each stronger sentence names the figure it rests on.
     *
     * @param  array<string,mixed>  $block
     * @return array{tone: string, text: string}
     */
    public function verdict(array $block): array
    {
        $freshness = $block['freshness'] ?? [];
        $change = $block['change'] ?? [];

        // Data problems outrank performance: a figure from a source that stopped syncing is not a
        // result, and telling somebody their CPA rose when the truth is that half the data is
        // missing sends them to optimise a campaign that is fine.
        if (($freshness['sync_failed'] ?? false) === true) {
            $who = implode('، ', array_map(static fn (array $f): string => (string) ($f['name'] ?? $f['provider']), $freshness['failing'] ?? []));

            return [
                'tone' => 'bad',
                'text' => $this->ar()
                    ? "فشلت مزامنة {$who} — الأرقام أدناه ناقصة حتى تُعاد الإضافة."
                    : "The {$who} sync failed — the figures below are incomplete until it is reconnected.",
            ];
        }

        if (($block['budget'] ?? []) !== []) {
            $first = $block['budget'][0];
            $ahead = $first['direction'] === 'ahead';

            return [
                'tone' => 'warn',
                'text' => $this->ar()
                    ? ($ahead
                        ? "«{$first['campaign']}» تستهلك الميزانية أسرع من المخطط ({$first['pace']}×) — راجعها قبل نهاية اليوم."
                        : "«{$first['campaign']}» متأخرة عن خطة الإنفاق ({$first['pace']}×) — قد لا تستهلك ميزانيتها.")
                    : ($ahead
                        ? "“{$first['campaign']}” is spending ahead of plan ({$first['pace']}×) — worth a look today."
                        : "“{$first['campaign']}” is behind its spend plan ({$first['pace']}×) and may not use its budget."),
            ];
        }

        $cpa = $change['cpa'] ?? null;
        if ($cpa !== null && $cpa > 0.25) {
            return [
                'tone' => 'warn',
                'text' => $this->ar()
                    ? 'ارتفعت تكلفة النتيجة أكثر من الربع مقارنة بالأمس — ابدأ من المنصة الأضعف أدناه.'
                    : 'Cost per result rose by more than a quarter against yesterday — start with the weakest platform below.',
            ];
        }

        if ($cpa !== null && $cpa < -0.25) {
            return [
                'tone' => 'good',
                'text' => $this->ar()
                    ? 'انخفضت تكلفة النتيجة أكثر من الربع مقارنة بالأمس — تحقق مما تغيّر وثبّته.'
                    : 'Cost per result fell by more than a quarter against yesterday — find what changed and keep it.',
            ];
        }

        return [
            'tone' => 'neutral',
            'text' => $this->ar()
                ? 'لا شيء يستدعي تدخلًا اليوم — الأرقام ضمن مدى الأمس.'
                : 'Nothing needs you today — the figures are within yesterday’s range.',
        ];
    }

    private function notProvided(): string
    {
        return $this->ar() ? 'لم ترسله المنصة' : 'Not reported';
    }

    private function noData(): string
    {
        return $this->ar() ? 'لا توجد بيانات' : 'No data';
    }

    /**
     * The label, the shape and the good direction of every metric a digest can lead with — MAIL-005.
     *
     * The email's KPI cards used to be spend, results, cost-per-result and impressions on every
     * project. On a brand project the third of those is an arithmetic accident — spend divided by
     * whatever events happened to be reported — printed in bold beside three real figures, which is
     * the §14.6 mistake in an inbox where nobody can click through to check it.
     *
     * The keys and the wording match `metricCatalog.ts` on the client on purpose: a reader who opens
     * the dashboard from the email must find the same words on the same numbers.
     *
     * @var array<string, array{ar: string, en: string, kind: string, lower_is_better?: bool, neutral?: bool}>
     */
    private const METRICS = [
        'spend' => ['ar' => 'الإنفاق', 'en' => 'Spend', 'kind' => 'money', 'neutral' => true],
        'impressions' => ['ar' => 'الظهور', 'en' => 'Impressions', 'kind' => 'count', 'neutral' => true],
        'reach' => ['ar' => 'الوصول', 'en' => 'Reach', 'kind' => 'count'],
        'frequency' => ['ar' => 'التكرار', 'en' => 'Frequency', 'kind' => 'ratio', 'neutral' => true],
        'cpm' => ['ar' => 'تكلفة الألف ظهور', 'en' => 'CPM', 'kind' => 'money', 'lower_is_better' => true],
        'clicks' => ['ar' => 'النقرات', 'en' => 'Clicks', 'kind' => 'count'],
        'ctr' => ['ar' => 'معدل النقر', 'en' => 'CTR', 'kind' => 'percent'],
        'cpc' => ['ar' => 'تكلفة النقرة', 'en' => 'CPC', 'kind' => 'money', 'lower_is_better' => true],
        'landing_page_views' => ['ar' => 'زيارات الصفحة', 'en' => 'Landing page views', 'kind' => 'count'],
        'video_views' => ['ar' => 'مشاهدات الفيديو', 'en' => 'Video views', 'kind' => 'count'],
        'conversions' => ['ar' => 'النتائج', 'en' => 'Results', 'kind' => 'count'],
        'purchases' => ['ar' => 'المشتريات', 'en' => 'Purchases', 'kind' => 'count'],
        'revenue' => ['ar' => 'الإيرادات', 'en' => 'Revenue', 'kind' => 'money'],
        'cpa' => ['ar' => 'تكلفة النتيجة', 'en' => 'Cost per result', 'kind' => 'money', 'lower_is_better' => true],
        'roas' => ['ar' => 'العائد على الإنفاق', 'en' => 'ROAS', 'kind' => 'ratio'],
    ];

    /**
     * One KPI card, or null when the metric is not one this product measures.
     *
     * @param  array<string,mixed>  $totals
     * @param  array<string,bool>  $reported
     * @param  array<string,float|null>  $change
     * @return array<string,mixed>|null
     */
    public function card(string $key, array $totals, array $reported, array $change): ?array
    {
        $spec = self::METRICS[$key] ?? null;
        if ($spec === null) {
            return null;
        }

        $value = $totals[$key] ?? null;
        $delta = $change[$key] ?? null;

        return [
            'label' => $this->ar() ? $spec['ar'] : $spec['en'],
            'value' => match ($spec['kind']) {
                // `count` is the only one that consults `reported`: it is the only kind whose zero
                // could have been coalesced from a metric nobody ever sent.
                'count' => $this->count($totals, $reported, $key),
                'money' => $this->money(is_numeric($value) ? (float) $value : null),
                'percent' => $this->percent(is_numeric($value) ? (float) $value : null),
                default => $this->ratio(is_numeric($value) ? (float) $value : null),
            },
            'change' => $this->change($delta),
            // Neutral figures are never coloured: spending more is neither good nor bad on its own,
            // and an arrow beside it is a judgement the number does not support.
            'change_colour' => ($spec['neutral'] ?? false)
                ? '#8b9a97'
                : $this->changeColour($delta, lowerIsBetter: $spec['lower_is_better'] ?? false),
        ];
    }

    /**
     * The cards this project leads with, in the order its objective ranks them.
     *
     * Four is the ceiling because a phone shows two per row and a third row of figures is where an
     * email stops being scanned and starts being scrolled past.
     *
     * @param  array<string,mixed>  $block
     * @return list<array<string,mixed>>
     */
    public function cards(array $block): array
    {
        $totals = (array) ($block['totals'] ?? []);
        $reported = (array) ($block['reported'] ?? []);
        $change = (array) ($block['change'] ?? []);

        /*
         * No stated set means «several objectives, or none we can name» — the same answer the
         * reports give a mixed scope: the operational figures, which mean the same thing whatever
         * each campaign was for, and never a cost per result across them.
         */
        $keys = (array) ($block['metric_set'] ?? []);
        if ($keys === []) {
            $keys = ['spend', 'impressions', 'clicks', 'ctr'];
        }

        $out = [];
        foreach ($keys as $key) {
            $card = $this->card((string) $key, $totals, $reported, $change);
            if ($card !== null) {
                $out[] = $card;
            }
            if (count($out) === 4) {
                break;
            }
        }

        return $out;
    }
}
