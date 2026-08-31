<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Enums;

/**
 * INTEG-RUNTIME §8 — the whole vocabulary a metrics sync is allowed to speak.
 *
 * ## What this replaced
 *
 * There were five words and they answered two different questions at once. `partial` meant BOTH «the
 * provider had nothing for this window» and «the provider answered and we could not place some of the
 * rows» — an ordinary quiet Tuesday and a mapping defect, filed under one status, on one screen, in
 * one colour. Nobody reading a list of runs could tell which of the two they were looking at, so the
 * defect that produced zero metrics for a live Snapchat account looked exactly like a weekend.
 *
 * Splitting it is the entire point of this enum:
 *
 * - {@see self::NoData} — we asked, the provider answered, the answer was «nothing happened». Not an
 *   error, not a failure, and **never rendered in red**. An account that spent nothing yesterday is
 *   not a broken integration.
 * - {@see self::PartialMapping} — the provider returned rows and some of them could not be attached
 *   to a campaign we know about. That is ours to fix, and it is the one that must be visible.
 *
 * `success` is likewise narrowed: it now requires metrics to have actually landed. A run that
 * upserted zero rows is never success, because a green tick over an empty dashboard is the single
 * most expensive lie this pipeline can tell.
 */
enum SyncRunStatus: string
{
    /** Started, not finished. The only non-terminal value. */
    case Running = 'running';

    /** The provider answered, every row was placed, and metrics landed. */
    case Success = 'success';

    /** The provider answered and had no rows for this window. Ordinary. Not red. */
    case NoData = 'no_data';

    /** The provider answered; some rows named campaigns we have not discovered. */
    case PartialMapping = 'partial_mapping';

    /** We could not complete the request: a provider error, or nothing configured to call with. */
    case Failed = 'failed';

    /**
     * Nobody has said which project this account feeds, so nothing was fetched.
     *
     * Deliberately not `failed`: nothing broke, and the operator's next move — choose a project — has
     * nothing in common with the next move for a provider error.
     */
    case AwaitingAssignment = 'awaiting_assignment';

    /** Terminal states, in the order a summary should read them. */
    public const TERMINAL = [
        self::Success, self::NoData, self::PartialMapping, self::Failed, self::AwaitingAssignment,
    ];

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * Whether this outcome is something a human should act on.
     *
     * `no_data` is absent on purpose, and so is `success`. `partial_mapping` is present because rows
     * the product could not place are rows missing from a client's report.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Failed, self::PartialMapping, self::AwaitingAssignment], true);
    }
}
