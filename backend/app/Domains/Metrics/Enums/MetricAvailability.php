<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Enums;

/**
 * CANONICAL-METRIC-CATALOG-001 — the nine things a metric can be, none of which is «0».
 *
 * ## Why an enum and not a nullable number
 *
 * A figure that is absent has a reason, and the reasons call for opposite actions. This product has
 * already shipped three separate defects that were all the same mistake — collapsing a distinction
 * into a falsy value:
 *
 *   - money that could not be converted rendered as `0 SAR`, understating real spend as nothing;
 *   - a creative that never ran and a fetch that failed both rendered «لا توجد بيانات»;
 *   - a withheld spend read as zero, so «still spending on fatigued content» silently never fired.
 *
 * Each was invisible precisely because `0` and `null` are both falsy and neither carries a reason.
 *
 * ## The states
 *
 * `Reported` and `Derived` are the only two that carry a number a person may act on. `Zero` is a
 * measured nothing and is also actionable — it is not the same as any of the six below it, and
 * conflating them is the whole defect class.
 */
enum MetricAvailability: string
{
    /** The provider sent this figure for this entity, date and window. */
    case Reported = 'reported';

    /** Computed from figures that ARE reported, on a compatible basis. Never estimated. */
    case Derived = 'derived';

    /** Genuinely measured as nothing. A real answer, and different from every state below. */
    case Zero = 'zero';

    /** The provider supports it but sent nothing for this row. Not a zero. */
    case NotReported = 'not_reported';

    /** This provider has no such metric at all. Nothing is missing; it does not exist to fetch. */
    case Unsupported = 'unsupported';

    /** Money the platform reported that cannot be stated in the reporting currency (FX-001). */
    case Withheld = 'withheld';

    /** The call was made and failed. The number exists at the platform and we do not have it. */
    case Failed = 'failed';

    /** Not fetched for a policy or permission reason — scope, consent, account access. */
    case Blocked = 'blocked';

    /** Held, but older than the freshness this surface promises. A number with a caveat. */
    case Stale = 'stale';

    /** Whether this state carries a figure a reader may act on. */
    public function hasValue(): bool
    {
        return match ($this) {
            self::Reported, self::Derived, self::Zero, self::Stale => true,
            self::NotReported, self::Unsupported, self::Withheld, self::Failed, self::Blocked => false,
        };
    }

    /**
     * Whether this state is something an operator should DO something about.
     *
     * `Failed` and `Blocked` mean a working platform and a broken pipeline. `NotReported` and
     * `Unsupported` are simply how the world is. Rendering the second pair as warnings is how real
     * alerts stop being read.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Failed, self::Blocked, self::Stale => true,
            default => false,
        };
    }

    /** @return array{ar:string,en:string} */
    public function label(): array
    {
        return match ($this) {
            self::Reported, self::Derived, self::Zero => ['ar' => '', 'en' => ''],
            self::NotReported => ['ar' => 'غير مُرسَل', 'en' => 'Not reported'],
            self::Unsupported => ['ar' => 'غير متاح على هذه المنصة', 'en' => 'Not available on this platform'],
            self::Withheld => ['ar' => 'التحويل إلى عملة المشروع غير متاح', 'en' => 'Conversion to the project currency unavailable'],
            self::Failed => ['ar' => 'تعذّر جلب البيانات', 'en' => 'Could not be fetched'],
            self::Blocked => ['ar' => 'الوصول غير مسموح لهذا الحساب', 'en' => 'Access not granted for this account'],
            self::Stale => ['ar' => 'بيانات قديمة', 'en' => 'Stale'],
        };
    }
}
