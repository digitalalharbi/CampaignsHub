<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Projects\Models\Project;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * COMMERCE-TZ-001 — which clock a report's days are measured on.
 *
 * ## Why the client and not the server
 *
 * «5 August» is sixty thousand different seconds depending on who says it. A report window built on
 * the server's own clock asks a Riyadh client about a day that starts at three in the morning and
 * ends at three the next — so their «yesterday» holds three hours of the day before and misses three
 * of its own. Nobody sees this as a timezone problem; they see a total that does not match what they
 * counted, and they are right.
 *
 * From the CLIENT rather than the project, exactly as {@see ReportingCurrency} takes the currency: a
 * client is who the report is for, and one client's projects measured on different clocks would make
 * their own portfolio unaddable.
 *
 * ## This is NOT the merchant's clock
 *
 * A store keeps its own timezone and its own calendar date on every row (`placed_on`), and that is
 * what a merchant-day total is grouped by. The two coexist: the client asks about their day, and each
 * sale is still recorded on the day its merchant sold it. Collapsing them into one would mean either
 * telling the client their day is somebody else's, or telling a merchant their sales moved.
 */
final class ReportingTimezone
{
    /**
     * The home market's clock — where this product is read, and where the default belongs.
     *
     * A fallback of UTC would be worse than arbitrary: it is three hours off for essentially every
     * client this install has, and it is off in the direction that pushes late-evening orders into
     * the following day.
     */
    public const DEFAULT = 'Asia/Riyadh';

    /** @var array<string, ?string> project id → the client's zone (or null), memoised per request */
    private array $projects = [];

    /** The zone this project's report windows are measured in. Never null — reports need a clock. */
    public function forProject(string $projectId): string
    {
        return $this->forProjectOrNull($projectId) ?? self::DEFAULT;
    }

    /**
     * The client's stated zone, or null when they have not set one.
     *
     * The nullable form exists for `StoreTime`, which must tell «the client says Riyadh» apart from
     * «nobody said anything and we defaulted» — one is a fact about a business and the other is an
     * assumption that has to be recorded as such.
     */
    public function forProjectOrNull(string $projectId): ?string
    {
        if (array_key_exists($projectId, $this->projects)) {
            return $this->projects[$projectId];
        }

        $zone = Project::withoutGlobalScopes()
            ->with('clientWorkspace:id,timezone')
            ->find($projectId)?->clientWorkspace?->timezone;

        return $this->projects[$projectId] = $this->usable(is_string($zone) ? $zone : null);
    }

    /**
     * The window a caller asked for, as the instants it actually covers.
     *
     * Callers hand in whole dates — that is what every date picker in this product produces and what
     * `from=2026-08-05&to=2026-08-05` means. Turning them into instants is the whole job, and doing
     * it in one place is what stops the funnel, the dashboard and a client link disagreeing about
     * where a day begins.
     *
     * `to` is the last INSTANT of its day rather than the next midnight, so an order placed at
     * 23:59:59 is inside the day it was placed in and inside no other.
     *
     * ## The instants come back in UTC, and that is not cosmetic
     *
     * Eloquent binds a `DateTimeInterface` through `Y-m-d H:i:s` — **the offset is dropped**. Handing
     * a query a Carbon that reads 00:00 in Riyadh therefore asks Postgres for 00:00 UTC, three hours
     * late, and the first three hours of the client's day quietly leave every result. Converting here
     * means no caller has to remember; `from_date`/`to_date` carry the client's own dates for
     * anything that compares against a DATE column or renders the window back to a reader.
     *
     * @return array{from: Carbon, to: Carbon, from_date: string, to_date: string, timezone: string}
     */
    public function window(string $projectId, Carbon $from, Carbon $to): array
    {
        $zone = $this->forProject($projectId);

        // The DATE the caller meant, re-anchored. `toDateString()` recovers it whatever zone the
        // caller's Carbon happened to be built in, which is the only thing about it we can trust.
        $start = Carbon::parse($from->toDateString(), $zone)->startOfDay();
        $end = Carbon::parse($to->toDateString(), $zone)->endOfDay();

        return [
            'from' => $start->copy()->utc(),
            'to' => $end->copy()->utc(),
            'from_date' => $start->toDateString(),
            'to_date' => $end->toDateString(),
            'timezone' => $zone,
        ];
    }

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
            // A stored zone PHP cannot resolve is not a clock; treat it as unset rather than crash
            // every report the client owns.
            return null;
        }
    }
}
