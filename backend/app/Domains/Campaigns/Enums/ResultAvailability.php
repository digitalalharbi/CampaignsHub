<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * CONTENT-RESULT-AVAILABILITY-001 — what a result figure IS, when «0» and «—» are different claims.
 *
 * ## Why this is five states and not a boolean
 *
 * The first version of this answered «measured / not measured» from one piece of evidence: had this
 * project ever recorded a non-zero for the metric through this provider. That is an inference
 * wearing provenance's clothes, and it had two holes.
 *
 * A project may hold SEVERAL ad accounts of one provider. One account's historical purchase cannot
 * certify another account's zero — they are different advertisers' measurement setups, and lending
 * evidence between them is the same mistake as lending it between tenants, one scope smaller.
 *
 * And «never observed a non-zero» is not proof of «cannot measure». A new sales account with a
 * correctly installed pixel and no sales yet looks identical, in the data, to an account with no
 * pixel at all. Calling the first one unmeasurable is as wrong as calling the second one a measured
 * zero — the product simply does not know, and there is a state for not knowing.
 *
 * So: evidence that a metric IS measurable is positive and usable; the absence of that evidence is
 * not negative proof of anything.
 */
enum ResultAvailability: string
{
    /** A figure arrived and is not zero. Nothing to explain — a number proves its own measurement. */
    case ReportedValue = 'reported_value';

    /**
     * Zero, and the EXACT account that served this has measured the metric before.
     *
     * The only state in which a zero is shown as a zero, because it is the only one where «this ad
     * sold nothing» is a thing the product can actually say.
     */
    case RealZeroConfirmed = 'real_zero_confirmed';

    /** The provider sent no value for this metric here — an absent or null field, not a zero. */
    case NotReported = 'not_reported';

    /**
     * Zero, with no reliable proof either way about whether this account measures it at all.
     *
     * Deliberately NOT called «not provided»: the provider did send something. What is unknown is
     * whether that something means anything, and saying «not provided» would be a second unsupported
     * claim replacing the first.
     */
    case MeasurementUnverified = 'measurement_unverified';

    /**
     * The figure exists for the campaign or the account in this period and is not this creative's.
     *
     * A number nobody can pin to one ad is not that ad's zero and not that ad's figure.
     */
    case NotAttributable = 'not_attributable';

    /** Whether a surface may print the figure beside this state, rather than a dash. */
    public function showsFigure(): bool
    {
        return $this === self::ReportedValue || $this === self::RealZeroConfirmed;
    }

    /**
     * Fold a set of states into ONE, conservatively — for an aggregate spanning several creatives
     * or several accounts.
     *
     * A positive figure anywhere in the set proves the sum is a real figure, so it wins outright.
     * Otherwise a set that does not agree is not a verified anything, and says so: one account's
     * evidence may not silently certify its neighbour's zero just because they were added together.
     *
     * @param  list<self>  $states
     */
    public static function fold(array $states): ?self
    {
        if ($states === []) {
            return null;
        }

        if (in_array(self::ReportedValue, $states, true)) {
            return self::ReportedValue;
        }

        $distinct = array_values(array_unique(array_map(static fn (self $s): string => $s->value, $states)));

        return count($distinct) === 1 ? self::from($distinct[0]) : self::MeasurementUnverified;
    }
}
