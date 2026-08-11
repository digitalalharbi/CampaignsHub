<?php

declare(strict_types=1);

namespace App\Domains\Commerce\ValueObjects;

use Illuminate\Support\Carbon;

/**
 * COMMERCE-TZ-001 — one moment, resolved once, with the zone it was resolved in.
 *
 * Three facts travel together because separating them is how the zone gets lost:
 *
 *  - `instant` — the absolute moment, stored as `timestamptz` and correct for every reader anywhere.
 *  - `timezone` — the zone the merchant's clock runs on. Kept because a date is meaningless without it.
 *  - `localDate` — the merchant's own calendar date, settled here rather than re-derived at read
 *    time. Re-deriving would make «which day did this sell on» a property of whoever is looking.
 *
 * `source` names which link of the chain answered: the payload's own zone, the store's, the client
 * workspace's, or UTC as a stated assumption. A reader can tell a fact from a guess.
 */
final readonly class StoreInstant
{
    public function __construct(
        public Carbon $instant,
        public string $timezone,
        public string $localDate,
        public string $source,
    ) {}

    /** True when nothing anywhere stated a zone and UTC had to be assumed. */
    public function isAssumed(): bool
    {
        return $this->source === 'assumed_utc';
    }

    /**
     * The three columns, named for the timestamp they belong to.
     *
     * @return array<string,mixed>
     */
    public function columns(string $atColumn, string $onColumn): array
    {
        return [
            $atColumn => $this->instant,
            $atColumn.'_timezone' => $this->timezone,
            $onColumn => $this->localDate,
            'time_source' => $this->source,
        ];
    }
}
