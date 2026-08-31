<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Reporting;

use DateTimeZone;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * SNAP-WINDOW-001 — a reporting window expressed in the AD ACCOUNT'S day, not ours.
 *
 * ## The live failure this exists for
 *
 * The first real Snapchat metrics sync returned **0 metrics** and this error:
 *
 * > Request cannot be processed due to validation error
 *
 * `SnapchatConnector::fetchInsights()` built its range as a string literal:
 *
 * ```php
 * 'start_time' => $from.'T00:00:00.000-00:00',
 * 'end_time'   => $to.'T00:00:00.000-00:00',
 * ```
 *
 * — **UTC midnight, for every account on the platform.** Snapchat's measurement reference states the
 * rule plainly: «time must be of day boundary, start_time and end_time must be both specified, or
 * neither», and its own DAY responses come back on the ad account's offset
 * (`2016-08-05T22:00:00.000-07:00`). For an account in `Asia/Riyadh` (UTC+3), UTC midnight is 03:00
 * local — not a day boundary — so every DAY request this product made for a non-UTC account was
 * refused before it was read.
 *
 * That is why structure synced and metrics did not: structure never calls `/stats`.
 *
 * ## Why the timezone is required rather than defaulted
 *
 * Defaulting to UTC is what broke it. Defaulting to `Asia/Riyadh` would break it for everyone else
 * and would be the same mistake wearing a different constant — the account's timezone is a fact the
 * provider tells us at discovery, and an account whose timezone we never captured is an account we
 * cannot honestly report on. It fails, with a message naming the fix, rather than guessing and
 * producing a number that silently belongs to the wrong day.
 */
final class ReportingWindow
{
    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $timezone,
    ) {}

    /**
     * A window covering whole local days, from the start of `$from` to the start of the day AFTER `$to`.
     *
     * `end` is EXCLUSIVE. That is the same convention Snapchat's own DAY series uses — its last
     * bucket runs `[end-1day, end)` — and it is what makes a single-day request expressible at all:
     * a start equal to its end is a zero-length range, which no provider accepts and which is the
     * shape a naive «from = to = today» produces.
     *
     * @throws RuntimeException when the account's timezone is unknown
     */
    public static function localDays(?string $timezone, string $from, string $to): self
    {
        $zone = self::zoneOr(
            $timezone,
            'This account has no timezone recorded, so a reporting day cannot be placed. '
                .'Refresh the account from the provider and try again.',
        );

        $start = Carbon::parse($from, $zone)->startOfDay();
        $end = Carbon::parse($to, $zone)->startOfDay()->addDay();

        if (! $end->greaterThan($start)) {
            // Reachable when `to` precedes `from` — a caller's mistake, and one that would otherwise
            // reach the provider as a range it can only refuse.
            $end = $start->copy()->addDay();
        }

        return new self($start, $end, $zone->getName());
    }

    /**
     * ISO 8601 with the account's own UTC offset, which is the form Snapchat returns and accepts.
     *
     * `2026-08-16T00:00:00.000+03:00` rather than `2026-08-16T00:00:00.000-00:00`: same instant
     * arithmetic, and only the first is a day boundary as far as the account is concerned.
     */
    public function startIso(): string
    {
        return $this->iso($this->start);
    }

    public function endIso(): string
    {
        return $this->iso($this->end);
    }

    /**
     * Split into consecutive sub-windows of at most `$days` local days each.
     *
     * A first sync asks for a month at once, and a provider that caps a DAY range refuses the whole
     * request rather than truncating it — so the customer's very first sync is the one that fails.
     * Chunking keeps every call inside whatever the cap is, and because each chunk is upserted
     * idempotently on `(account, campaign, date, metric)` the pieces reassemble with no seam.
     *
     * @return list<self>
     */
    public function chunked(int $days): array
    {
        $days = max(1, $days);
        $chunks = [];
        $cursor = $this->start->copy();

        while ($cursor->lessThan($this->end)) {
            $next = $cursor->copy()->addDays($days);
            $chunks[] = new self($cursor->copy(), $next->greaterThan($this->end) ? $this->end->copy() : $next, $this->timezone);
            $cursor = $next;
        }

        return $chunks === [] ? [$this] : $chunks;
    }

    /** How many whole local days this window covers. */
    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end);
    }

    private function iso(Carbon $moment): string
    {
        return $moment->format('Y-m-d\TH:i:s.v').$moment->format('P');
    }

    private static function zoneOr(?string $timezone, string $message): DateTimeZone
    {
        if ($timezone === null || trim($timezone) === '') {
            throw new RuntimeException($message);
        }

        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            // A timezone the provider gave us that PHP does not recognise is a data problem worth
            // saying out loud, not one to paper over with UTC.
            throw new RuntimeException("The account's timezone «{$timezone}» is not one this server recognises.");
        }
    }
}
