<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\MarketingPath;
use Illuminate\Support\Carbon;

/**
 * §15.10 — what the figures actually say about a creative, and what to do about it.
 *
 * ## Every sentence here is derived, none of it is written in advance
 *
 * «جرّب محتوى جديدًا» is not an insight; it is a sentence that would be true of any account on any
 * day, and a report full of them teaches the reader to stop reading. So a rule fires only when a
 * specific creative crossed a specific threshold against a specific comparison, and the sentence it
 * produces names the creative, the metric, both figures and the window they came from. Nothing is
 * emitted for an account with nothing to say — an empty list is the honest answer to a quiet month.
 *
 * ## It reads rows, not the database
 *
 * Like {@see CreativePulse}, this class performs no SQL. It takes rows already shaped by
 * {@see CreativeRows::present()} — the same rows the library renders and the dashboard ranks — so an
 * insight can never be computed from figures the reader cannot go and look at. That is §15.17 held
 * at the level where it matters most: a recommendation the underlying page contradicts is worse than
 * no recommendation.
 *
 * ## Two comparisons, and a rule may only use one of them
 *
 * A creative is judged either against ITSELF in the previous period, or against its PEERS on the same
 * marketing path. Never against creatives on another path: «your awareness video has a worse CPA than
 * your sales video» is arithmetic, not analysis, and acting on it would be a mistake this system
 * caused.
 *
 * ## Confidence is stated, and «insufficient» is a verdict rather than a silence
 *
 * Every rule that reads a ratio requires {@see MIN_IMPRESSIONS} behind it, because a CTR from forty
 * impressions is not a small measurement — it is not a measurement. But dropping thin creatives
 * entirely is what makes «Insufficient Data» read as silence, so one rule fires exactly there:
 * `spend_without_evidence`, for budget going into something almost nobody has seen. It carries
 * `confidence: insufficient_data`, which no other rule can produce and which never becomes «stable».
 *
 * ## AI is not involved
 *
 * `generated_by` is `rules` on every item, and it is on every item so that a future model-written
 * insight cannot arrive undeclared. §15's requirement that AI output be labelled and human-reviewed
 * is enforced by there being a field that must be filled in, not by a convention.
 */
final class CreativeInsights
{
    /** Below this, a ratio is noise — the same floor the rankings and the fatigue check use. */
    public const MIN_IMPRESSIONS = CreativePulse::MIN_IMPRESSIONS;

    /** A movement smaller than this is ordinary variance, not a finding. */
    private const MATERIAL = 0.15;

    /** «Stable» for the scaling rule: a cost that moved less than this while spend grew. */
    private const STABLE = 0.10;

    /** Spend growth that counts as scaling. */
    private const SCALING = 0.25;

    /** An average frequency at or above this is saturation on any platform. */
    private const SATURATED_FREQUENCY = 3.0;

    /** A peer comparison needs a peer group; below this, the median is one creative's opinion. */
    private const MIN_PEERS = 3;

    /** How far past the path median a creative must sit to be called an opportunity. */
    private const STANDOUT = 0.25;

    /** How far below the median the WEAK half of a mismatch must sit. */
    private const SHORTFALL = 0.6;

    /** How many items travel to the reader. The full count travels beside them — never a silent cut. */
    private const LIMIT = 12;

    /**
     * @param  list<array<string, mixed>>  $rows  presented creatives, with `previous` and `fatigue`
     * @return array<string, mixed>
     */
    public function build(array $rows, Carbon $from, Carbon $to): array
    {
        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();

        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days];
        $previousPeriod = [
            'from' => $prevTo->copy()->subDays($days - 1)->toDateString(),
            'to' => $prevTo->toDateString(),
        ];

        $withMetrics = array_values(array_filter($rows, static fn (array $r): bool => is_array($r['metrics'] ?? null)));
        $medians = $this->medians($withMetrics);

        $items = [];
        foreach ($withMetrics as $row) {
            foreach ($this->rulesFor($row, $medians) as $item) {
                $items[] = $item + [
                    'period' => $period,
                    'previous_period' => $previousPeriod,
                    'generated_by' => 'rules',
                    'needs_human_review' => false,
                ] + $this->subject($row);
            }
        }

        foreach ($this->crossPlatform($withMetrics) as $item) {
            $items[] = $item + [
                'period' => $period,
                'previous_period' => $previousPeriod,
                'generated_by' => 'rules',
                'needs_human_review' => false,
            ];
        }

        /*
         * Ordered by what the reader should act on, not by what the loop produced.
         *
         * Warnings first because they are money leaving; opportunities next because they are money
         * available; confirmations last. Inside a band, the biggest spend — a 3% CTR drop on the
         * creative carrying most of the budget is a different sentence from the same drop on one
         * running at 40 riyals a day.
         */
        $rank = ['warning' => 0, 'opportunity' => 1, 'positive' => 2];
        usort($items, static function (array $a, array $b) use ($rank): int {
            return [$rank[$a['severity']] ?? 3, -($a['spend'] ?? 0)]
                <=> [$rank[$b['severity']] ?? 3, -($b['spend'] ?? 0)];
        });

        $shown = array_slice($items, 0, self::LIMIT);

        return [
            'items' => $shown,
            'total' => count($items),
            'shown' => count($shown),
            'evidence' => [
                'min_impressions' => self::MIN_IMPRESSIONS,
                'material_change' => self::MATERIAL,
                'min_peers' => self::MIN_PEERS,
            ],
            'period' => $period,
            'previous_period' => $previousPeriod,
        ];
    }

    // ---- the rules ----------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, float>>  $medians
     * @return list<array<string, mixed>>
     */
    private function rulesFor(array $row, array $medians): array
    {
        $out = [];
        $path = (string) ($row['path'] ?? MarketingPath::Awareness->value);
        $isVideo = ($row['preview']['kind'] ?? null) === 'video';

        /*
         * The one finding that belongs BELOW the evidence floor.
         *
         * Every rule after this needs enough delivery to mean anything, and skipping a thin creative
         * entirely is what makes «Insufficient Data» read as silence. But budget going into
         * something almost nobody has seen is precisely what a client should be told — the honest
         * sentence is not «this creative is performing badly», it is «we cannot yet say, and it is
         * costing money». So it is reported, and its confidence says exactly that.
         */
        if (! $this->evidenced($row) && is_numeric($row['metrics']['spend'] ?? null) && (float) $row['metrics']['spend'] > 0.0) {
            $impressions = $row['metrics']['impressions'] ?? null;

            return [$this->finding($row, 'spend_without_evidence', 'warning', ['spend', 'impressions'], null,
                'إنفاق دون بيانات كافية للحكم',
                'Spending without enough data to judge',
                'أنفق هذا المحتوى '.$this->num((float) $row['metrics']['spend']).' مقابل '
                    .(is_numeric($impressions) ? $this->num((float) $impressions) : '0')
                    .' ظهور، وهو دون الحد الذي يسمح بقراءة نسبه ('.$this->num(self::MIN_IMPRESSIONS).').',
                'This creative spent '.$this->num((float) $row['metrics']['spend']).' for '
                    .(is_numeric($impressions) ? $this->num((float) $impressions) : '0')
                    .' impressions, below the floor at which its ratios can be read ('.$this->num(self::MIN_IMPRESSIONS).').',
                'إمّا أن تمنحه ميزانية كافية ليصبح قابلًا للقياس، أو توقفه؛ لا تقرأ نسبه الحالية كأداء.',
                'Either give it enough budget to become measurable or stop it — do not read its current ratios as performance.',
            )];
        }

        // ---- against itself, in the window before ----------------------------------------------

        if ($m = $this->moved($row, 'ctr', wantedUp: false)) {
            $out[] = $this->finding($row, 'ctr_decline', 'warning', ['ctr'], $m,
                'انخفاض معدل النقر',
                'Click-through rate is falling',
                'انخفض معدل النقر من '.$this->pct($m['previous']).' إلى '.$this->pct($m['current']).' مقارنة بالفترة السابقة.',
                'Click-through fell from '.$this->pct($m['previous']).' to '.$this->pct($m['current']).' against the previous period.',
                'جهّز نسخة جديدة من هذا المحتوى بافتتاحية مختلفة، وأوقف النسخة الحالية تدريجيًا بدل إيقافها دفعة واحدة.',
                'Prepare a new cut of this creative with a different opening, and taper the current one rather than stopping it at once.',
            );
        }

        if ($m = $this->moved($row, 'cpc', wantedUp: true)) {
            $out[] = $this->finding($row, 'cpc_increase', 'warning', ['cpc', 'ctr'], $m,
                'ارتفاع تكلفة النقرة',
                'Cost per click is rising',
                'ارتفعت تكلفة النقرة من '.$this->num($m['previous']).' إلى '.$this->num($m['current']).' لكل نقرة.',
                'Cost per click rose from '.$this->num($m['previous']).' to '.$this->num($m['current']).' per click.',
                'راجع الاستهداف والمزايدة لهذا المحتوى قبل زيادة الميزانية؛ الارتفاع هنا يسبق ارتفاع تكلفة النتيجة عادةً.',
                'Review this creative’s targeting and bidding before adding budget — a rise here usually precedes a rise in cost per result.',
            );
        }

        if ($path === MarketingPath::Conversion->value && ($m = $this->moved($row, 'cpa', wantedUp: true))) {
            $out[] = $this->finding($row, 'cpa_increase', 'warning', ['cpa', 'conversions', 'spend'], $m,
                'ارتفاع تكلفة الطلب',
                'Cost per order is rising',
                'ارتفعت تكلفة الطلب من '.$this->num($m['previous']).' إلى '.$this->num($m['current']).'.',
                'Cost per order rose from '.$this->num($m['previous']).' to '.$this->num($m['current']).'.',
                'قارن هذا المحتوى بأفضل محتوى على المسار نفسه، وحوّل جزءًا من ميزانيته إليه إن استمر الفارق أسبوعًا.',
                'Compare this creative with the best on the same path, and move part of its budget there if the gap holds for a week.',
            );
        }

        if ($m = $this->moved($row, 'roas', wantedUp: false)) {
            $out[] = $this->finding($row, 'roas_decline', 'warning', ['roas', 'revenue', 'spend'], $m,
                'تراجع العائد على الإنفاق',
                'Return on ad spend is falling',
                'تراجع العائد من '.$this->num($m['previous']).'× إلى '.$this->num($m['current']).'×.',
                'Return fell from '.$this->num($m['previous']).'× to '.$this->num($m['current']).'×.',
                'تحقّق أولًا من تغيّر متوسط قيمة الطلب أو العروض في المتجر قبل تعديل المحتوى نفسه.',
                'Check whether basket value or store offers changed before changing the creative itself.',
            );
        }

        if ($isVideo && ($m = $this->moved($row, 'view_rate', wantedUp: false))) {
            $out[] = $this->finding($row, 'view_rate_decline', 'warning', ['view_rate', 'video_views'], $m,
                'تراجع نسبة المشاهدة',
                'View rate is falling',
                'تراجعت نسبة المشاهدة من '.$this->pct($m['previous']).' إلى '.$this->pct($m['current']).'.',
                'View rate fell from '.$this->pct($m['previous']).' to '.$this->pct($m['current']).'.',
                'أعد تحرير الثواني الثلاث الأولى؛ التراجع في بداية المشاهدة لا يُعالَج بزيادة الميزانية.',
                'Re-cut the first three seconds — a fall at the start of the view is not fixed by adding budget.',
            );
        }

        if ($isVideo && ($m = $this->moved($row, 'completion_rate', wantedUp: false))) {
            $out[] = $this->finding($row, 'completion_rate_decline', 'warning', ['completion_rate', 'video_avg_watch_seconds'], $m,
                'تراجع نسبة إكمال المشاهدة',
                'Completion rate is falling',
                'تراجعت نسبة الإكمال من '.$this->pct($m['previous']).' إلى '.$this->pct($m['current']).'.',
                'Completion fell from '.$this->pct($m['previous']).' to '.$this->pct($m['current']).'.',
                'اختصر النسخة الحالية أو انقل الرسالة الأساسية إلى النصف الأول من الفيديو.',
                'Shorten this cut, or move the core message into the first half of the video.',
            );
        }

        if ($m = $this->moved($row, 'conversion_rate', wantedUp: true)) {
            $out[] = $this->finding($row, 'conversion_rate_improving', 'positive', ['conversion_rate', 'conversions'], $m,
                'تحسّن معدل التحويل',
                'Conversion rate is improving',
                'ارتفع معدل التحويل من '.$this->pct($m['previous']).' إلى '.$this->pct($m['current']).'.',
                'Conversion rate rose from '.$this->pct($m['previous']).' to '.$this->pct($m['current']).'.',
                'ثبّت هذا المحتوى في المجموعة الإعلانية الحالية قبل توسيع الاستهداف، حتى يبقى التحسّن قابلًا للعزو إليه.',
                'Hold this creative in its current ad set before widening targeting, so the gain stays attributable to it.',
            );
        }

        if ($scaled = $this->scaledSteadily($row)) {
            $out[] = $scaled;
        }

        if ($saturated = $this->saturated($row)) {
            $out[] = $saturated;
        }

        // ---- against its peers, on the same path -----------------------------------------------

        foreach ($this->versusPeers($row, $medians[$path] ?? [], $path) as $item) {
            $out[] = $item;
        }

        // ---- from the fatigue assessment -------------------------------------------------------

        if ($fatigued = $this->fatigued($row)) {
            $out[] = $fatigued;
        }

        return $out;
    }

    /**
     * Spend grew materially and the cost per result did not — the one finding that says «do more».
     *
     * Both halves are required. Rising spend alone is a budget decision somebody already made, and a
     * flat CPA alone is a creative nobody is pushing; together they are evidence that this creative
     * has headroom, which is the only honest basis for telling a client to spend more on it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function scaledSteadily(array $row): ?array
    {
        $spend = $this->change($row, 'spend');
        $cost = $this->change($row, 'cpa') ?? $this->change($row, 'cpc');

        if ($spend === null || $cost === null || ! $this->evidenced($row)) {
            return null;
        }
        if ($spend['change'] < self::SCALING || abs($cost['change']) > self::STABLE) {
            return null;
        }

        return $this->finding($row, 'cpa_stable_while_scaling', 'opportunity', ['spend', 'cpa', 'cpc', 'conversions'], $spend,
            'الإنفاق يتوسّع وتكلفة النتيجة ثابتة',
            'Spend is scaling and cost per result is holding',
            'ارتفع الإنفاق '.$this->pct($spend['change']).' بينما تغيّرت تكلفة النتيجة '.$this->pct($cost['change']).' فقط.',
            'Spend rose '.$this->pct($spend['change']).' while cost per result moved only '.$this->pct($cost['change']).'.',
            'هذا المحتوى يحتمل زيادة إضافية؛ ارفع ميزانيته تدريجيًا وراقب تكلفة النتيجة أسبوعيًا.',
            'This creative has headroom — raise its budget in steps and watch cost per result weekly.',
        );
    }

    /**
     * The same people, too many times.
     *
     * Frequency is judged on its CURRENT level rather than on its movement, because saturation is a
     * threshold and not a trend: a creative that has sat at 4.0 all month is not «stable», it is
     * being shown to the same audience four times over and its click-through is falling for a reason
     * no new edit will fix.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function saturated(array $row): ?array
    {
        $frequency = $row['metrics']['frequency'] ?? null;

        if (! is_numeric($frequency) || (float) $frequency < self::SATURATED_FREQUENCY || ! $this->evidenced($row)) {
            return null;
        }

        $before = $row['previous']['frequency'] ?? null;
        $movement = is_numeric($before) && (float) $before > 0.0
            ? ['metric' => 'frequency', 'current' => (float) $frequency, 'previous' => (float) $before, 'change' => round(((float) $frequency - (float) $before) / (float) $before, 4)]
            : ['metric' => 'frequency', 'current' => (float) $frequency, 'previous' => null, 'change' => null];

        return $this->finding($row, 'frequency_saturation', 'warning', ['frequency', 'reach', 'ctr'], $movement,
            'تشبّع التكرار',
            'Frequency has saturated',
            'بلغ متوسط التكرار '.$this->num((float) $frequency).' مرة لكل شخص خلال الفترة.',
            'Average frequency reached '.$this->num((float) $frequency).' per person over the period.',
            'وسّع الجمهور أو أضف محتوى بديلًا في المجموعة نفسها؛ رفع الميزانية عند هذا التكرار يزيد التكلفة دون زيادة الوصول.',
            'Widen the audience or add an alternative creative to the same ad set — adding budget at this frequency raises cost without reaching anybody new.',
        );
    }

    /**
     * How this creative sits against the median of creatives doing the SAME job.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, float>  $median
     * @return list<array<string, mixed>>
     */
    private function versusPeers(array $row, array $median, string $path): array
    {
        if ($median === [] || ! $this->evidenced($row)) {
            return [];
        }

        $out = [];
        $value = static fn (string $key) => is_numeric($row['metrics'][$key] ?? null) ? (float) $row['metrics'][$key] : null;

        // A creative beating its path's median decisively while carrying below-median spend.
        $axis = match ($path) {
            MarketingPath::Traffic->value => ['ctr', true],
            MarketingPath::Conversion->value => isset($median['roas']) ? ['roas', true] : ['cpa', false],
            default => ['cpm', false],
        };

        [$key, $higherWins] = $axis;
        $mine = $value($key);
        $par = $median[$key] ?? null;
        $spend = $value('spend');
        $medianSpend = $median['spend'] ?? null;

        if ($mine !== null && $par !== null && $par != 0.0 && $spend !== null && $medianSpend !== null) {
            $edge = $higherWins ? ($mine - $par) / abs($par) : ($par - $mine) / abs($par);

            if ($edge >= self::STANDOUT && $spend < $medianSpend) {
                $out[] = $this->finding($row, 'scaling_opportunity', 'opportunity', [$key, 'spend'],
                    ['metric' => $key, 'current' => $mine, 'previous' => $par, 'change' => round($edge, 4)],
                    'أداء أفضل من متوسط المسار بميزانية أقل',
                    'Beating the path median on a smaller budget',
                    'يتفوّق هذا المحتوى على وسيط المسار في '.$this->label($key, 'ar').' بنسبة '.$this->pct($edge).' رغم أن إنفاقه أقل من الوسيط.',
                    'This creative beats the path median on '.$this->label($key, 'en').' by '.$this->pct($edge).' while spending below the median.',
                    'انقل جزءًا من ميزانية المحتوى الأضعف على المسار نفسه إلى هذا المحتوى، وقِس الأثر بعد أسبوع.',
                    'Move part of a weaker same-path creative’s budget here, and measure after a week.',
                    peerBased: true,
                );
            }
        }

        // Mismatches — strong at one stage of the journey, weak at the next.
        foreach ([
            ['strong_hook_weak_completion', 'hook_rate', 'completion_rate',
                'بداية قوية ونهاية ضعيفة', 'Strong hook, weak completion',
                'أعد بناء منتصف الفيديو؛ الافتتاحية تعمل والرسالة تفقد المشاهد بعدها.',
                'Rebuild the middle of the video — the opening works and the message loses the viewer after it.'],
            ['strong_ctr_weak_conversion', 'ctr', 'conversion_rate',
                'نقرات قوية وتحويل ضعيف', 'Strong clicks, weak conversion',
                'راجع صفحة الوصول ومطابقتها لوعد الإعلان؛ المشكلة بعد النقرة وليست في المحتوى.',
                'Review the landing page against what the ad promises — the loss is after the click, not in the creative.'],
            ['strong_view_weak_ctr', 'view_rate', 'ctr',
                'مشاهدة قوية ونقر ضعيف', 'Strong views, weak clicks',
                'أضف دعوة صريحة للإجراء داخل الفيديو وفي النص المرافق؛ المشاهدة وحدها لا تنقل أحدًا إلى الموقع.',
                'Add an explicit call to action inside the video and in the copy beside it — views alone move nobody to the site.'],
        ] as [$key, $strongKey, $weakKey, $titleAr, $titleEn, $actionAr, $actionEn]) {
            $strong = $value($strongKey);
            $weak = $value($weakKey);
            $strongPar = $median[$strongKey] ?? null;
            $weakPar = $median[$weakKey] ?? null;

            if ($strong === null || $weak === null || $strongPar === null || $weakPar === null || $weakPar == 0.0) {
                continue;
            }
            if ($strong < $strongPar || $weak > $weakPar * self::SHORTFALL) {
                continue;
            }

            $out[] = $this->finding($row, $key, 'opportunity', [$strongKey, $weakKey],
                ['metric' => $weakKey, 'current' => $weak, 'previous' => $weakPar, 'change' => round(($weak - $weakPar) / abs($weakPar), 4)],
                $titleAr, $titleEn,
                $this->label($strongKey, 'ar').' عند '.$this->pct($strong).' فوق وسيط المسار ('.$this->pct($strongPar).')، بينما '.$this->label($weakKey, 'ar').' عند '.$this->pct($weak).' مقابل وسيط '.$this->pct($weakPar).'.',
                $this->label($strongKey, 'en').' is '.$this->pct($strong).' against a path median of '.$this->pct($strongPar).', while '.$this->label($weakKey, 'en').' is '.$this->pct($weak).' against '.$this->pct($weakPar).'.',
                $actionAr, $actionEn,
                peerBased: true,
            );
        }

        return $out;
    }

    /**
     * The fatigue assessment, restated as something to do — and only where money is still going.
     *
     * A fatigued creative that stopped spending is history. A fatigued creative still taking budget
     * is the single most actionable line a creative report can carry, so it is the one that becomes
     * an insight, with the assessment's own signals travelling as the evidence behind it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function fatigued(array $row): ?array
    {
        if (($row['fatigue']['status'] ?? null) !== CreativeFatigue::FATIGUED) {
            return null;
        }

        $spend = $row['metrics']['spend'] ?? null;
        if (! is_numeric($spend) || (float) $spend <= 0.0) {
            return null;
        }

        $signals = (array) ($row['fatigue']['signals'] ?? []);

        $item = $this->finding($row, 'creative_fatigue', 'warning', ['spend', 'ctr', 'frequency', 'cpm'], null,
            'إجهاد المحتوى مع استمرار الإنفاق',
            'Creative fatigue while spend continues',
            (string) ($row['fatigue']['note_ar'] ?? 'تراجع الأداء بشكل مستمر خلال الفترة.'),
            (string) ($row['fatigue']['note_en'] ?? 'Performance declined consistently over the period.'),
            'استبدل هذا المحتوى أو أوقفه؛ الإنفاق مستمر عليه بينما مؤشراته تتراجع.',
            'Replace or stop this creative — spend is continuing while its indicators fall.',
        );

        $item['fatigue_signals'] = $signals;

        return $item;
    }

    /**
     * The same asset, run on more than one platform, where one platform did materially better.
     *
     * Only a GROUPED creative can produce this. Two different creatives on two platforms differ in
     * more than the platform, so a «move budget to TikTok» built on them would be crediting the
     * placement for the content. A group is one file, which is what makes the comparison about where
     * it ran.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function crossPlatform(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (($id = $row['group_id'] ?? null) !== null) {
                $groups[(string) $id][] = $row;
            }
        }

        $out = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }

            $path = (string) ($members[0]['path'] ?? MarketingPath::Awareness->value);
            [$key, $higherWins] = match ($path) {
                MarketingPath::Traffic->value => ['ctr', true],
                MarketingPath::Conversion->value => ['roas', true],
                default => ['cpm', false],
            };

            $scored = array_values(array_filter(
                $members,
                static fn (array $r): bool => is_numeric($r['metrics'][$key] ?? null),
            ));

            if (count($scored) < 2) {
                continue;
            }

            usort($scored, static function (array $a, array $b) use ($key, $higherWins): int {
                $x = (float) $a['metrics'][$key];
                $y = (float) $b['metrics'][$key];

                return $higherWins ? $y <=> $x : $x <=> $y;
            });

            $best = $scored[0];
            $worst = $scored[count($scored) - 1];
            $bestValue = (float) $best['metrics'][$key];
            $worstValue = (float) $worst['metrics'][$key];

            if ($worstValue == 0.0) {
                continue;
            }

            $gap = $higherWins
                ? ($bestValue - $worstValue) / abs($worstValue)
                : ($worstValue - $bestValue) / abs($worstValue);

            // The same half-percent tolerance the dashboard's platform card uses. A gap the reader
            // cannot see in the rounded figures is not a gap worth moving a budget on.
            if ($gap < self::MATERIAL) {
                continue;
            }

            $item = $this->finding($best, 'cross_platform_opportunity', 'opportunity', [$key, 'spend'],
                ['metric' => $key, 'current' => $bestValue, 'previous' => $worstValue, 'change' => round($gap, 4)],
                'المحتوى نفسه يؤدي أفضل على منصة بعينها',
                'The same creative performs better on one platform',
                'المحتوى نفسه سجّل '.$this->num($bestValue).' على '.(string) ($best['provider'] ?? '—').' مقابل '.$this->num($worstValue).' على '.(string) ($worst['provider'] ?? '—').' في '.$this->label($key, 'ar').'.',
                'The same creative recorded '.$this->num($bestValue).' on '.(string) ($best['provider'] ?? '—').' against '.$this->num($worstValue).' on '.(string) ($worst['provider'] ?? '—').' for '.$this->label($key, 'en').'.',
                'حوّل جزءًا من ميزانية المنصة الأضعف إلى المنصة الأفضل لهذا المحتوى تحديدًا، لا لكل المحتويات.',
                'Move part of the weaker platform’s budget to the stronger one for THIS creative, not for the account.',
            ) + $this->subject($best);

            $item['platforms'] = array_map(static fn (array $r): array => [
                'creative_id' => $r['id'] ?? null,
                'provider' => $r['provider'] ?? null,
                'value' => is_numeric($r['metrics'][$key] ?? null) ? (float) $r['metrics'][$key] : null,
                'spend' => is_numeric($r['metrics']['spend'] ?? null) ? (float) $r['metrics']['spend'] : null,
            ], $members);

            $out[] = $item;
        }

        return $out;
    }

    // ---- construction -------------------------------------------------------------------------

    /**
     * One finding, with everything a reader needs to check it themselves.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $supporting
     * @param  array<string, mixed>|null  $movement
     * @return array<string, mixed>
     */
    private function finding(
        array $row,
        string $key,
        string $severity,
        array $supporting,
        ?array $movement,
        string $titleAr,
        string $titleEn,
        string $detailAr,
        string $detailEn,
        string $actionAr,
        string $actionEn,
        bool $peerBased = false,
    ): array {
        $metrics = [];
        foreach ($supporting as $metric) {
            if (is_numeric($row['metrics'][$metric] ?? null)) {
                $metrics[$metric] = (float) $row['metrics'][$metric];
            }
        }

        $previous = [];
        foreach ($supporting as $metric) {
            if (is_numeric($row['previous'][$metric] ?? null)) {
                $previous[$metric] = (float) $row['previous'][$metric];
            }
        }

        return [
            /*
             * The identity of this FINDING, as distinct from the identity of the RULE.
             *
             * `key` is the rule — «ctr fell» — and it fires once per creative, so any surface
             * listing findings across a whole account receives the same `key` many times. Anything
             * de-duplicating by it silently drops findings while still reporting the honest total:
             * live, a dashboard promising «12 of 91» rendered nine, because React collapsed the
             * repeats of `spend_without_evidence`. The rule stays readable; this is what is unique.
             */
            'id' => $key.':'.($row['id'] ?? ''),
            'key' => $key,
            'severity' => $severity,
            'comparison' => $peerBased ? 'peers' : 'previous_period',
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'detail_ar' => $detailAr,
            'detail_en' => $detailEn,
            'action_ar' => $actionAr,
            'action_en' => $actionEn,
            'supporting_metrics' => $metrics,
            'previous_metrics' => $previous === [] ? null : $previous,
            'movement' => $movement,
            'confidence' => $this->confidence($row, $movement),
            'spend' => is_numeric($row['metrics']['spend'] ?? null) ? (float) $row['metrics']['spend'] : null,
        ];
    }

    /**
     * Who this is about — named the same way on every insight, so an export can table them.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function subject(array $row): array
    {
        return [
            'creative_id' => $row['id'] ?? null,
            'creative_name' => $row['name'] ?? null,
            'objective' => $row['objective'] ?? null,
            'path' => $row['path'] ?? null,
            'provider' => $row['provider'] ?? null,
            'campaign_id' => $row['campaign_id'] ?? null,
            'campaign_name' => $row['campaign_name'] ?? null,
            'kind' => $row['preview']['kind'] ?? null,
        ];
    }

    /**
     * How firm this finding is — and `insufficient_data` is a real answer, not a hidden one.
     *
     * A creative under the impression floor can still be flagged: money going into something nobody
     * has seen is exactly what a client should hear about. What it cannot be is presented with the
     * same confidence as a finding drawn from fifty thousand impressions.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $movement
     */
    private function confidence(array $row, ?array $movement): string
    {
        if (! $this->evidenced($row)) {
            return 'insufficient_data';
        }

        $impressions = (float) $row['metrics']['impressions'];
        $change = is_numeric($movement['change'] ?? null) ? abs((float) $movement['change']) : 0.0;

        return $impressions >= self::MIN_IMPRESSIONS * 5 && $change >= self::MATERIAL * 2 ? 'high' : 'medium';
    }

    // ---- measurement --------------------------------------------------------------------------

    /**
     * A material movement in the direction this rule is watching, or null.
     *
     * `$wantedUp` is the direction that FIRES the rule, not the direction that is good: a falling CTR
     * and a rising conversion rate are the same arithmetic and opposite findings, so the caller says
     * which way it cares about and the sign is never inferred from the metric's name.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function moved(array $row, string $key, bool $wantedUp): ?array
    {
        $change = $this->change($row, $key);

        if ($change === null || ! $this->evidenced($row)) {
            return null;
        }
        if (abs($change['change']) < self::MATERIAL) {
            return null;
        }

        return ($change['change'] > 0) === $wantedUp ? $change : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{metric: string, current: float, previous: float, change: float}|null
     */
    private function change(array $row, string $key): ?array
    {
        $now = $row['metrics'][$key] ?? null;
        $before = $row['previous'][$key] ?? null;

        if (! is_numeric($now) || ! is_numeric($before) || (float) $before == 0.0) {
            return null;
        }

        return [
            'metric' => $key,
            'current' => (float) $now,
            'previous' => (float) $before,
            'change' => round(((float) $now - (float) $before) / abs((float) $before), 4),
        ];
    }

    /**
     * The median of each metric, per marketing path.
     *
     * A median rather than a mean: one creative running at a fiftieth of the cost of everything else
     * drags a mean far enough that half the account looks like it is beating the average, and the
     * «opportunity» list fills with creatives that are merely ordinary.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, float>>
     */
    private function medians(array $rows): array
    {
        $keys = ['ctr', 'cpc', 'cpm', 'cpa', 'roas', 'conversion_rate', 'view_rate', 'completion_rate', 'hook_rate', 'spend'];
        $byPath = [];

        foreach ($rows as $row) {
            if (! $this->evidenced($row)) {
                continue;   // the median is what «normal» looks like; a flukey row is not normal.
            }

            $path = (string) ($row['path'] ?? MarketingPath::Awareness->value);
            foreach ($keys as $key) {
                if (is_numeric($row['metrics'][$key] ?? null)) {
                    $byPath[$path][$key][] = (float) $row['metrics'][$key];
                }
            }
            $byPath[$path]['__count'] ??= [];
            $byPath[$path]['__count'][] = 1.0;
        }

        $out = [];
        foreach ($byPath as $path => $sets) {
            if (count($sets['__count'] ?? []) < self::MIN_PEERS) {
                continue;   // too few to have a normal.
            }

            foreach ($sets as $key => $values) {
                if ($key === '__count') {
                    continue;
                }
                sort($values);
                $count = count($values);
                $out[$path][$key] = $count % 2 === 1
                    ? $values[intdiv($count, 2)]
                    : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function evidenced(array $row): bool
    {
        $impressions = $row['metrics']['impressions'] ?? null;

        return is_numeric($impressions) && (float) $impressions >= self::MIN_IMPRESSIONS;
    }

    // ---- formatting ---------------------------------------------------------------------------

    /*
     * Latin digits, always — the product's standing rule for Arabic copy, and the reason these two
     * helpers exist rather than the sentence being assembled in the browser: the same text has to
     * reach a PDF and an Excel sheet, where there is no formatter to reach for.
     */

    /**
     * A metric's NAME, in the reader's language.
     *
     * The sentences interpolated the raw column key, so an Arabic client read «يتفوّق … في ctr»: a
     * database identifier sitting mid-sentence, which reads as an untranslated string rather than as
     * a technical term somebody chose. Caught by opening the Arabic report.
     *
     * Only the keys these rules actually name are listed; anything else falls back to the key, which
     * is the honest failure — a wrong Arabic word invented for a metric would be worse than the key.
     */
    private function label(string $key, string $locale): string
    {
        $names = [
            'ctr' => ['ar' => 'معدل النقر', 'en' => 'click-through rate'],
            'cpc' => ['ar' => 'تكلفة النقرة', 'en' => 'cost per click'],
            'cpm' => ['ar' => 'تكلفة الألف ظهور', 'en' => 'cost per thousand impressions'],
            'cpa' => ['ar' => 'تكلفة الطلب', 'en' => 'cost per order'],
            'roas' => ['ar' => 'العائد على الإنفاق', 'en' => 'return on ad spend'],
            'conversion_rate' => ['ar' => 'معدل التحويل', 'en' => 'conversion rate'],
            'view_rate' => ['ar' => 'نسبة المشاهدة', 'en' => 'view rate'],
            'completion_rate' => ['ar' => 'نسبة إكمال المشاهدة', 'en' => 'completion rate'],
            'hook_rate' => ['ar' => 'نسبة الجذب في الثواني الأولى', 'en' => 'hook rate'],
            'spend' => ['ar' => 'الإنفاق', 'en' => 'spend'],
        ];

        return $names[$key][$locale] ?? $key;
    }

    private function pct(?float $value): string
    {
        return $value === null ? '—' : number_format($value * 100, 2).'%';
    }

    private function num(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2);
    }
}
