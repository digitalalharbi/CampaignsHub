<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * EMAIL-PERF-DASHBOARDS-001 — the figures an alert carries, as one lead and a compact strip.
 *
 * An alert email was three sentences per finding. The rule that raised it already knows the numbers
 * (the ratio of spend to plan, the limit and what was consumed, the rate now and before), and a
 * reader on a phone acts on the number before the sentence. This reads the event's own context —
 * nothing is recomputed — and returns null wherever the context carries no figure a reader can act
 * on (a sync failure, an expiring token), so those findings keep their sentence and nothing else.
 *
 * @return array{lead: array{label: string, value: string}, strip: list<array{label: string, value: string}>}|null
 */
final class AlertFigures
{
    /** @param  array<string, mixed>  $context */
    public static function from(array $context, bool $ar): ?array
    {
        // Budget pace — spend against the plan, as the share of what was expected by now.
        if (isset($context['ratio']) && is_numeric($context['ratio']) && ($context['basis_class'] ?? 'measured') === 'measured') {
            $currency = isset($context['budget_currency']) ? (string) $context['budget_currency'] : null;

            return [
                'lead' => ['label' => $ar ? 'من الإنفاق المتوقع حتى الآن' : 'of the spend expected by now', 'value' => self::percent((float) $context['ratio'])],
                'strip' => array_values(array_filter([
                    isset($context['spend']) ? ['label' => $ar ? 'المصروف' : 'Spent', 'value' => self::amount($context['spend'], $currency)] : null,
                    isset($context['budget']) ? ['label' => $ar ? 'الميزانية' : 'Budget', 'value' => self::amount($context['budget'], $currency)] : null,
                ])),
            ];
        }

        // Spend limit — what was consumed against the limit, and the threshold that was crossed.
        if (isset($context['consumed'], $context['limit'])) {
            $currency = isset($context['currency']) ? (string) $context['currency'] : null;

            return [
                'lead' => ['label' => $ar ? 'المستهلك من الحدّ' : 'Consumed of the limit', 'value' => self::amount($context['consumed'], $currency)],
                'strip' => array_values(array_filter([
                    ['label' => $ar ? 'الحدّ' : 'Limit', 'value' => self::amount($context['limit'], $currency)],
                    isset($context['threshold']) && is_numeric($context['threshold']) ? ['label' => $ar ? 'العتبة' : 'Threshold', 'value' => self::percent((float) $context['threshold'] > 1 ? (float) $context['threshold'] / 100 : (float) $context['threshold'])] : null,
                ])),
            ];
        }

        // A rate or return now against before — `<key>_current` / `<key>_previous`, as the rules write them.
        foreach ($context as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '_current') || ! is_numeric($value)) {
                continue;
            }
            $metric = substr($key, 0, -8);
            $previous = $context[$metric.'_previous'] ?? null;
            $strip = [];
            if (is_numeric($previous)) {
                $strip[] = ['label' => $ar ? 'قبل' : 'Before', 'value' => self::metric($metric, (float) $previous)];
                if ((float) $previous != 0.0) {
                    $change = ((float) $value - (float) $previous) / abs((float) $previous);
                    $strip[] = ['label' => $ar ? 'التغيّر' : 'Change', 'value' => ($change > 0 ? '+' : '').self::percent($change)];
                }
            }
            if (isset($context['window_days']) && is_numeric($context['window_days'])) {
                $strip[] = ['label' => $ar ? 'النافذة' : 'Window', 'value' => (int) $context['window_days'].' '.($ar ? 'يوم' : 'days')];
            }

            return ['lead' => ['label' => self::metricLabel($metric, $ar), 'value' => self::metric($metric, (float) $value)], 'strip' => $strip];
        }

        // Spend with no results — the count that is zero, beside the money it cost.
        if (array_key_exists('conversions', $context) && isset($context['spend']) && is_numeric($context['spend'])) {
            return [
                'lead' => ['label' => $ar ? 'النتائج' : 'Results', 'value' => number_format((float) ($context['conversions'] ?? 0))],
                'strip' => array_values(array_filter([
                    ['label' => $ar ? 'المصروف' : 'Spent', 'value' => self::amount($context['spend'], null)],
                    isset($context['days']) && is_numeric($context['days']) ? ['label' => $ar ? 'الأيام' : 'Days', 'value' => (string) (int) $context['days']] : null,
                ])),
            ];
        }

        return null;
    }

    private static function percent(float $ratio): string
    {
        return number_format($ratio * 100, abs($ratio * 100) >= 10 ? 0 : 1).'%';
    }

    private static function amount(mixed $value, ?string $currency): string
    {
        $n = number_format((float) $value, 2);

        return $currency === null || $currency === '' ? $n : $n.' '.$currency;
    }

    private static function metric(string $metric, float $value): string
    {
        return match (true) {
            $metric === 'roas' => number_format($value, 2).'×',
            in_array($metric, ['ctr', 'conversion_rate', 'cvr', 'engagement_rate'], true) => self::percent($value),
            default => number_format($value, 2),
        };
    }

    private static function metricLabel(string $metric, bool $ar): string
    {
        $labels = [
            'roas' => ['العائد على الإنفاق الآن', 'Return on ad spend now'],
            'ctr' => ['نسبة النقر الآن', 'Click-through rate now'],
            'cpa' => ['تكلفة النتيجة الآن', 'Cost per result now'],
            'cpl' => ['تكلفة العميل المحتمل الآن', 'Cost per lead now'],
            'cpc' => ['تكلفة النقرة الآن', 'Cost per click now'],
            'cpm' => ['تكلفة الألف ظهور الآن', 'CPM now'],
            'frequency' => ['التكرار الآن', 'Frequency now'],
            'conversion_rate' => ['معدل التحويل الآن', 'Conversion rate now'],
        ];
        $pair = $labels[$metric] ?? [$metric.' الآن', $metric.' now'];

        return $ar ? $pair[0] : $pair[1];
    }
}
