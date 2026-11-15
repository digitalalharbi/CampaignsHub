<?php

declare(strict_types=1);

namespace App\Domains\Automation\Support;

use App\Domains\Funding\Support\FundingMoney;

/**
 * CONS-I-DRYRUN — the words of a rule reading: how a value is written and why a value is missing, in plain Arabic.
 *
 * A dry run used to print the technical key and a bare number («cpa@7d: 0.27»). It now names the indicator, writes the
 * money in the display currency with its mark, and gives «غير متوفرة» plus the exact cause for a figure nobody has.
 */
final class RuleReadings
{
    private const REASONS = [
        'no_daily_budget' => 'المنصة ما زوّدتنا بميزانية يومية لهذي الحملة',
        'no_spend_today' => 'ما فيه إنفاق مسجّل اليوم بعد',
        'no_rate' => 'ما فيه سعر صرف لتحويل الميزانية إلى {currency}',
        'unknown_currency' => 'عملة الميزانية غير معروفة',
        'no_creation_date' => 'المنصة ما أرسلت تاريخ إنشاء الحملة',
        'no_status' => 'حالة الحملة غير معروفة',
        'no_comparison' => 'ما فيه بيانات في الفترة السابقة للمقارنة',
        'not_reported' => 'المنصة ما أبلغت عن هذا المؤشر في الفترة',
    ];

    public static function reasonAr(string $code, string $currency): string
    {
        return str_replace('{currency}', $currency, self::REASONS[$code] ?? self::REASONS['not_reported']);
    }

    public static function days(int $n): string
    {
        return match (true) {
            $n === 1 => 'يوم',
            $n === 2 => 'يومين',
            $n >= 3 && $n <= 10 => $n.' أيام',
            default => $n.' يوم',
        };
    }

    public static function valueText(float|string|null $value, string $kind, ?string $currency, ?string $reasonCode): string
    {
        if ($value === null) {
            return 'غير متوفرة';
        }
        if (is_string($value)) {
            return $value;
        }

        return match ($kind) {
            'money' => FundingMoney::withMark(number_format($value, 2), $currency),
            'percent' => number_format($value, 1).'%',
            'ratio' => number_format($value, 2).'×',
            'days' => number_format($value, 0).' '.($value == 1.0 ? 'يوم' : 'أيام'),
            default => number_format($value, 0),
        };
    }
}
