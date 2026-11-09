<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Support\AdPlatforms;

/**
 * EMAIL-DIGESTS-OPERATIONAL-001 — a finding of the report engine, said plainly in the reader's language.
 *
 * `ReportObservations` produces the findings (the thresholds live there and only there) as Arabic
 * sentences. This turns the SAME structured finding — kind, metric, platform, value, change — into a
 * headline, one sentence with the numbers, and the next step, in Arabic or English, with no metric key
 * and no English code leaking into an Arabic mail. It never names a campaign: the finding's scope is a
 * platform or the period.
 */
final class PlainFindings
{
    /** @var array<string, array{0: string, 1: string}> metric key → [ar, en] */
    public const METRICS = [
        'spend' => ['الإنفاق', 'spend'],
        'impressions' => ['الظهور', 'impressions'],
        'reach' => ['الوصول', 'reach'],
        'clicks' => ['النقرات', 'clicks'],
        'ctr' => ['معدل النقر', 'click-through rate'],
        'cpc' => ['تكلفة النقرة', 'cost per click'],
        'cpm' => ['تكلفة الألف ظهور', 'cost per 1,000 impressions'],
        'cpa' => ['تكلفة النتيجة', 'cost per result'],
        'cpl' => ['تكلفة العميل المحتمل', 'cost per lead'],
        'cpi' => ['تكلفة التثبيت', 'cost per install'],
        'roas' => ['العائد على الإنفاق', 'return on ad spend'],
        'revenue' => ['قيمة الطلبات', 'order value'],
        'purchases' => ['الطلبات', 'orders'],
        'leads' => ['العملاء المحتملون', 'leads'],
        'installs' => ['التثبيتات', 'installs'],
        'conversions' => ['النتائج', 'results'],
        'conversion_rate' => ['معدل التحويل', 'conversion rate'],
        'engagement_rate' => ['معدل التفاعل', 'engagement rate'],
        'frequency' => ['التكرار', 'frequency'],
    ];

    /** Metrics where a rise is bad news. */
    private const LOWER_IS_BETTER = ['cpa', 'cpc', 'cpm', 'cpl', 'cpi', 'cost_per_view', 'cpe'];

    public static function metricLabel(string $key, bool $ar): string
    {
        $pair = self::METRICS[$key] ?? null;

        // An unknown key is never printed: a generic word beats a code the reader cannot read.
        return $pair === null ? ($ar ? 'المؤشر' : 'the metric') : ($ar ? $pair[0] : $pair[1]);
    }

    /**
     * @param  array<string,mixed>  $note  one entry of `ReportObservations::build()`
     * @return array{tone: string, headline: string, text: string, action: string}
     */
    public static function say(array $note, bool $ar): array
    {
        $kind = (string) ($note['kind'] ?? '');
        $metric = (string) ($note['metric'] ?? '');
        $label = self::metricLabel($metric, $ar);
        $value = (string) ($note['value'] ?? '');
        $change = is_numeric($note['change'] ?? null) ? (float) $note['change'] : null;
        $pct = $change === null ? '' : number_format(abs($change) * 100, abs($change) * 100 >= 10 ? 0 : 1).'%';
        $platform = (string) (($note['scope']['name'] ?? '') ?: '');
        // The finding's scope name is stored in Arabic; the platform is named from its key so an English mail never carries it.
        if (str_starts_with((string) ($note['id'] ?? ''), 'budget:') && strlen((string) $note['id']) > 7) {
            $platform = AdPlatforms::name(substr((string) $note['id'], 7), $ar ? 'ar' : 'en');
        }
        $severity = (string) ($note['severity'] ?? 'info');
        $tone = match ($severity) {
            'critical' => 'bad', 'warning' => 'warn', 'positive' => 'good', default => 'neutral',
        };
        $rose = $change !== null && $change > 0;
        $costLike = in_array($metric, self::LOWER_IS_BETTER, true);

        switch ($kind) {
            case 'period_comparison':
                $good = $costLike ? ! $rose : $rose;

                return [
                    'tone' => $tone,
                    'headline' => $ar ? "{$label} ".($rose ? 'ارتفع' : 'انخفض')." {$pct}" : ucfirst($label).' '.($rose ? 'rose' : 'fell')." {$pct}",
                    'text' => $ar ? "صار {$value} مقابل الفترة السابقة." : "Now {$value} against the previous period.",
                    'action' => $good
                        ? ($ar ? 'افتح المنصات وحدّد ما تغيّر ثم ثبّته.' : 'Open the platforms, find what changed, and keep it.')
                        : ($ar ? 'افتح المنصات وابدأ بالأضعف.' : 'Open the platforms and start with the weakest.'),
                ];
            case 'rising_cost':
                return [
                    'tone' => 'warn',
                    'headline' => $ar ? "{$label} ارتفعت {$pct}" : ucfirst($label)." rose {$pct}",
                    'text' => $ar ? "صارت {$value}. قد يكون السبب منافسة أعلى أو ضعف المحتوى." : "Now {$value}. Often higher competition or tired content.",
                    'action' => $ar ? 'قارن المنصات وراجع المحتوى الأضعف.' : 'Compare the platforms and review the weakest content.',
                ];
            case 'falling_rate':
                return [
                    'tone' => 'warn',
                    'headline' => $ar ? "{$label} تراجع {$pct}" : ucfirst($label)." fell {$pct}",
                    'text' => $ar ? "صار {$value}. قد يعني أن المحتوى بدأ يفقد أثره." : "Now {$value}. The content may be wearing out.",
                    'action' => $ar ? 'راجع أداء المحتوى وجدّد الأضعف.' : 'Review content performance and refresh the weakest.',
                ];
            case 'frequency_saturation':
                return [
                    'tone' => 'warn',
                    'headline' => $ar ? 'الجمهور يرى إعلانك مرات كثيرة' : 'People are seeing your ads too often',
                    'text' => $ar ? ($value !== '' ? "متوسط التكرار {$value}." : 'التكرار مرتفع.') : ($value !== '' ? "Average frequency {$value}." : 'Frequency is high.'),
                    'action' => $ar ? 'وسّع الجمهور أو حدّث المحتوى.' : 'Widen the audience or refresh the content.',
                ];
            case 'budget_pace':
                $fast = $severity === 'critical';
                $who = $platform !== '' ? $platform : ($ar ? 'المنصة' : 'the platform');

                return [
                    'tone' => $tone,
                    'headline' => $ar ? "الإنفاق على {$who} ".($fast ? 'أسرع من الخطة' : 'أبطأ من الخطة') : "Spend on {$who} is ".($fast ? 'ahead of plan' : 'behind plan'),
                    'text' => $ar ? "وصل إلى {$value} من المتوقع حتى الآن." : "At {$value} of what was expected by now.",
                    'action' => $fast
                        ? ($ar ? 'قرّر اليوم: أبقِ الميزانية أو عدّلها.' : 'Decide today: keep the budget or adjust it.')
                        : ($ar ? 'تأكد من أن الحملات تعمل وأن الميزانية ستُستهلك.' : 'Check the campaigns are running and the budget will be used.'),
                ];
            case 'internal_spend_limit':
                return [
                    'tone' => $tone,
                    'headline' => $ar ? 'اقترب الإنفاق من الحد الذي وضعتَه' : 'Spend is close to the limit you set',
                    'text' => $ar ? (string) ($note['detail'] ?? '') : 'The internal spend limit for this project has been passed.',
                    'action' => $ar ? 'راجع الحد في صفحة الميزانية.' : 'Review the limit on the budget page.',
                ];
            case 'reallocation':
                return [
                    'tone' => 'good',
                    'headline' => $ar ? (string) ($note['title'] ?? 'فرصة لتوزيع الميزانية') : 'Budget could work harder elsewhere',
                    'text' => $ar ? (string) ($note['detail'] ?? '') : 'One platform delivers results at a clearly lower cost than another.',
                    'action' => $ar ? 'افتح المنصات وقارن قبل أي نقل.' : 'Open the platforms and compare before moving anything.',
                ];
            case 'data_gap':
                return [
                    'tone' => 'neutral',
                    'headline' => $ar ? 'بعض الأرقام لم تصل بعد' : 'Some figures have not arrived',
                    'text' => $ar ? 'منصة لم ترسل بعض المؤشرات لهذه الفترة، فلا تعدّها صفرًا.' : 'A platform did not send some metrics for this period. Do not read them as zero.',
                    'action' => $ar ? 'تحقق من الربط إن استمر الأمر.' : 'Check the connection if it continues.',
                ];
            case 'stale_data':
                return [
                    'tone' => $tone,
                    'headline' => $severity === 'critical' ? ($ar ? 'توقفت مزامنة البيانات' : 'Data sync has stopped') : ($ar ? 'البيانات لم تتحدث مؤخرًا' : 'Data has not refreshed lately'),
                    'text' => $ar ? 'الأرقام قد تكون ناقصة حتى تعود المزامنة.' : 'Figures may be incomplete until syncing resumes.',
                    'action' => $ar ? 'افتح صفحة الربط وأعد ربط المصدر.' : 'Open the connections page and reconnect the source.',
                ];
        }

        return [
            'tone' => $tone,
            'headline' => $ar ? 'ملاحظة تحتاج مراجعتك' : 'A finding needs your review',
            'text' => $ar ? (string) ($note['detail'] ?? '') : '',
            'action' => $ar ? 'افتح التقرير وراجعها.' : 'Open the report and review it.',
        ];
    }
}
