<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\ValueObjects\StoreInstant;
use App\Domains\Metrics\Services\ReportingTimezone;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * COMMERCE-TZ-001 — turning what a store said about a moment into a moment.
 *
 * ## The chain, in the order a fact beats a guess
 *
 * 1. **The payload's own zone.** Salla wraps its dates as `{ date, timezone }`; Zid sends a string
 *    that may carry an offset. Either way the provider has stated the zone for THIS row, which beats
 *    anything configured elsewhere — a merchant who changed their store's timezone last month has
 *    old orders that were placed under the old one, and the payload remembers that and we do not.
 * 2. **The store's timezone**, from `external_accounts.timezone` — what the merchant's admin says
 *    their shop runs on.
 * 3. **The client workspace's timezone** — the business this project reports to. A better guess than
 *    UTC for a Saudi merchant and still a guess, so it is recorded as one.
 * 4. **UTC, assumed.** The row is kept: dropping an order because we could not place it to the hour
 *    loses a real sale from every total, and that is the worse error. What is not acceptable is the
 *    assumption being invisible, which is why it is written on the row and counted on the funnel.
 *
 * ## Why an offset in the string wins outright
 *
 * `2026-08-05T01:30:00+03:00` is already absolute. Applying a zone on top of it would shift a correct
 * instant by three more hours — the failure mode where fixing a timezone bug produces a bigger one in
 * the opposite direction. So the string is asked whether it carries its own offset BEFORE any zone is
 * considered.
 *
 * ## Zones are named, never reduced to a number of minutes
 *
 * `Asia/Riyadh` is +03 all year; `Europe/London` is not. Resolving a zone to a fixed offset — the
 * obvious shortcut — is an hour wrong for half of every year, and which half depends on when the code
 * happens to run. Every conversion here goes through the named zone so the rules in force ON THAT
 * DATE apply, which is also what makes a historical re-sync reproduce the same instant.
 */
final class StoreTime
{
    /** An ISO-8601 offset or a `Z` at the end of the string: the payload stating its own instant. */
    private const CARRIES_OFFSET = '/(Z|[+-]\d{2}:?\d{2})$/';

    public function __construct(private readonly ReportingTimezone $reporting) {}

    /**
     * Resolve one timestamp from a store payload.
     *
     * `$raw` is whatever the connector passed through: Salla's `{date, timezone}` array, a string, or
     * null. Null in, null out — an order with no timestamp is given no date rather than today's.
     */
    public function resolve(mixed $raw, ?string $storeTimezone, ?string $projectId): ?StoreInstant
    {
        [$text, $payloadZone] = $this->unwrap($raw);

        if ($text === null) {
            return null;
        }

        // 1. The payload states the instant itself. Nothing may be applied on top of it.
        if (preg_match(self::CARRIES_OFFSET, $text) === 1) {
            $instant = $this->parse($text, null);

            if ($instant === null) {
                return null;
            }

            /*
             * The merchant's DATE still needs a zone — an absolute instant does not know whose
             * calendar to fall on. The payload's stated zone if it has one, else the store's, else
             * the instant's own offset, which is the closest thing to the merchant's clock available.
             */
            $zone = $this->firstUsable([$payloadZone, $storeTimezone]) ?? $instant->getTimezone()->getName();

            return new StoreInstant($instant->utc(), $zone, $instant->copy()->setTimezone($zone)->toDateString(), 'payload');
        }

        // 2–4. A wall clock. It means nothing until it is anchored, so find the best zone available.
        [$zone, $source] = $this->zoneFor($payloadZone, $storeTimezone, $projectId);

        $instant = $this->parse($text, $zone);

        if ($instant === null) {
            return null;
        }

        return new StoreInstant(
            $instant->utc(),
            $zone,
            // Taken from the local reading, not from the UTC one — that IS the merchant's date.
            $instant->copy()->setTimezone($zone)->toDateString(),
            $source,
        );
    }

    /**
     * @return array{0: string, 1: string} the zone to anchor in, and where it came from
     */
    private function zoneFor(?string $payloadZone, ?string $storeTimezone, ?string $projectId): array
    {
        if (($zone = $this->usable($payloadZone)) !== null) {
            return [$zone, 'payload'];
        }

        if (($zone = $this->usable($storeTimezone)) !== null) {
            return [$zone, 'store'];
        }

        if ($projectId !== null && ($zone = $this->usable($this->reporting->forProjectOrNull($projectId))) !== null) {
            return [$zone, 'workspace'];
        }

        return ['UTC', 'assumed_utc'];
    }

    /**
     * Salla's wrapper, a bare string, or nothing.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function unwrap(mixed $raw): array
    {
        if ($raw instanceof Carbon) {
            // Already an instant — a caller that did its own parsing. Kept, with its own zone.
            return [$raw->toIso8601String(), $raw->getTimezone()->getName()];
        }

        if (is_array($raw)) {
            $text = isset($raw['date']) ? trim((string) $raw['date']) : '';
            $zone = isset($raw['timezone']) ? trim((string) $raw['timezone']) : '';

            return [$text === '' ? null : $text, $zone === '' ? null : $zone];
        }

        $text = is_string($raw) ? trim($raw) : '';

        return [$text === '' ? null : $text, null];
    }

    /** @param list<?string> $candidates */
    private function firstUsable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (($zone = $this->usable($candidate)) !== null) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * A zone name PHP actually knows.
     *
     * A provider sending something unrecognisable must not take the whole import down, and must not
     * quietly become UTC either — returning null sends it one step down the chain, where the reason
     * is recorded.
     */
    private function usable(?string $zone): ?string
    {
        $name = trim((string) $zone);

        if ($name === '') {
            return null;
        }

        try {
            new DateTimeZone($name);

            return $name;
        } catch (Throwable) {
            return null;
        }
    }

    private function parse(string $text, ?string $zone): ?Carbon
    {
        try {
            return $zone === null ? Carbon::parse($text) : Carbon::parse($text, $zone);
        } catch (Throwable) {
            // A date we cannot read is not worth failing an order over; the raw payload keeps it.
            return null;
        }
    }
}
