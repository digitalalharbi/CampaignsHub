<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Enums;

/**
 * PARTIAL-WITHHELD-001 — the six things a SCOPE's money can be, one axis above `MetricAvailability`.
 *
 * `MetricAvailability` describes one figure's provenance. This describes how a SUM composed. A scope
 * can hold rows that converted to the reporting currency AND rows that could not, and the sum of the
 * two is not a figure in any single currency. The prior code collapsed this to a boolean — «was
 * anything withheld» — and then, the moment converted money existed, returned only the converted
 * subset while presenting it as the whole. That understates real spend as plausibly-complete, which
 * is worse than a zero because a zero is obviously wrong and a partial total is not.
 *
 * Only `CompleteConverted`, `CompleteWithheld` and `Zero` are a single number. `Partial` and
 * `MixedCurrency` are explicitly NOT — every derived money metric over them fails closed.
 */
enum MoneyState: string
{
    /** Every contributing row converted to the reporting currency. */
    case CompleteConverted = 'complete_converted';

    /** Every withheld row shares one currency and nothing converted — a real total in that currency. */
    case CompleteWithheld = 'complete_withheld';

    /** Some money converted and some withheld — real money in two units, no rate to join them. */
    case Partial = 'partial';

    /** The withheld rows span more than one currency — a sum across them is not a quantity. */
    case MixedCurrency = 'mixed_currency';

    /** Nothing was ever reported. */
    case Absent = 'absent';

    /** Measured as nothing. A real answer, distinct from every state above. */
    case Zero = 'zero';

    /** Whether this state is a single figure a surface may print or divide. */
    public function isSingleTotal(): bool
    {
        return match ($this) {
            self::CompleteConverted, self::CompleteWithheld, self::Zero => true,
            self::Partial, self::MixedCurrency, self::Absent => false,
        };
    }
}
