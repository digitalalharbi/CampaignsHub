<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Coverage;

/**
 * AGGREGATION-TRUTH-001 — what one contributor did, distinguished from what it failed to say.
 *
 * ## Why an enum rather than a boolean
 *
 * `MetricsAggregator` decided «was this reported» from the PRESENCE OF A ROW, and every absence
 * therefore meant the same thing. Six different facts arrived as one number:
 *
 *   - the provider reported a real zero
 *   - the provider does not publish the metric at all
 *   - the campaign was not running
 *   - the sync FAILED
 *   - the sync has not covered the window yet
 *   - the money was withheld for want of an exchange rate
 *
 * Only the first is a measurement. The rest are absences, and the absence of a row is not evidence of
 * anything. Each state below must be provable from something that exists — a lifecycle date, a sync
 * checkpoint, a capability declaration, a provider response — never inferred from a missing row.
 *
 * ## The division that matters
 *
 * Two questions decide everything downstream, and they are NOT the same question:
 *
 *   1. Should this contributor have been reporting? (`isExpected`)
 *   2. Do we have its figures? (`contributes`)
 *
 * A contributor that was never expected is simply absent, and its absence takes nothing away from the
 * total. A contributor that WAS expected and is missing makes the total incomplete — and the total
 * must say so rather than publishing the remainder under the same label it would have used for the
 * whole. The damaging case is always the second being treated as the first.
 */
enum ContributionState: string
{
    /** The provider sent a figure. The only state that carries a measurement. */
    case ReportedValue = 'REPORTED_VALUE';

    /**
     * The provider sent a literal zero, and it means zero.
     *
     * Only ever set from provider evidence — a row that says 0, or coverage proving zero semantics.
     * Never from a missing row, which is the whole reason this enum exists.
     */
    case ReportedZero = 'REPORTED_ZERO';

    /** Outside its lifecycle — stopped, paused, or not yet started. Not a contributor. */
    case Inactive = 'INACTIVE';

    /** Eligible and running, and genuinely did nothing on this day. Not a contributor, not a zero. */
    case NoActivity = 'NO_ACTIVITY';

    /** Eligible, but nothing arrived and no evidence says why. Never a zero. */
    case NotReported = 'NOT_REPORTED';

    /** The connector does not publish this metric. Never a zero — see `reportedKeysByProvider`. */
    case Unsupported = 'UNSUPPORTED';

    /** Money that exists and cannot be compared: no FX rate for its own date (FX-001). */
    case WithheldFx = 'WITHHELD_FX';

    /** Some of the window arrived. A subset, and never presentable as the whole. */
    case Partial = 'PARTIAL';

    /** The sync has not covered this window. The figures exist at the provider and not here yet. */
    case Stale = 'STALE';

    /** The sync ran and failed. The most dangerous absence, because it looks exactly like a zero. */
    case Failed = 'FAILED';

    /** Expected, and the evidence does not say what happened. Fails closed, like `Failed`. */
    case Unknown = 'UNKNOWN';

    /**
     * Whether this contributor SHOULD be reporting figures for the window.
     *
     * `Inactive` is the only absence that is not a gap: a platform outside its lifecycle owes the
     * total nothing, and excluding it is correct rather than merely convenient. Everything else here
     * was expected, so its absence is missing information — which is a different thing from a zero and
     * must never be rendered as one.
     */
    public function isExpected(): bool
    {
        return $this !== self::Inactive;
    }

    /** Whether this state carries a figure that may enter a sum. */
    public function contributes(): bool
    {
        return $this === self::ReportedValue || $this === self::ReportedZero;
    }

    /**
     * Whether this state makes an aggregate incomplete.
     *
     * `NoActivity` is deliberately NOT here. A campaign that ran and spent nothing is a complete
     * answer — the day is measured, and the measurement is that nothing happened. `NotReported` IS
     * here, because «nothing arrived and nothing explains why» is the absence of an answer.
     */
    public function degradesTotal(): bool
    {
        return match ($this) {
            self::ReportedValue, self::ReportedZero, self::Inactive, self::NoActivity => false,
            default => true,
        };
    }
}
