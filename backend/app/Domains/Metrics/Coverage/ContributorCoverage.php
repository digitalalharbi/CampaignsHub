<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Coverage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AGGREGATION-TRUTH-001 — decides each contributor's state from EVIDENCE, never from a missing row.
 *
 * ## The rule this class exists to enforce
 *
 * «There is no row» is not a fact about the world. It is the absence of a fact, and it is produced
 * equally by a platform that spent nothing, a platform that was switched off, a sync that failed, a
 * sync that has not run, and a connector that does not publish the metric at all. Reading a business
 * zero out of it is the defect; reading anything at all out of it is the mistake underneath.
 *
 * So every state here is proven from something that exists:
 *
 *   - lifecycle    → `external_campaigns.status`, `starts_at`, `ends_at`
 *   - sync         → `metric_sync_runs`: status, window covered, error
 *   - connection   → `provider_connections.status`
 *   - figures      → the rows themselves, which prove presence and never prove absence
 *
 * ## Why the window matters and not just the run
 *
 * A sync that succeeded is not the same as a sync that covered the days being asked about. A provider
 * synced through 24 July has no opinion at all about August, and treating its silence as zero spend
 * for August is how «we spent 1,500» gets published when a third of the money is merely late. The
 * check is therefore whether the run's window REACHES the requested window's end, not whether a run
 * exists or whether it succeeded.
 */
final class ContributorCoverage
{
    /**
     * How far behind the requested window a provider may be before it counts as stale.
     *
     * Zero days: any gap between what was synced and what was asked for is a gap. A tolerance here
     * would be a quiet decision about how much missing money is acceptable, which is not a decision
     * this class is entitled to make on a reader's behalf.
     */
    private const STALE_TOLERANCE_DAYS = 0;

    /**
     * Every provider expected to contribute to this scope, with the state each is actually in.
     *
     * @param  list<string>|null  $providers  restrict to these providers, or null for all in scope
     */
    public function forWindow(
        string $tenantId,
        ?string $projectId,
        Carbon $from,
        Carbon $to,
        ?array $providers = null,
    ): AggregateCoverage {
        /*
         * Nobody can report a day that has not happened.
         *
         * The window a reader asks for routinely runs past today — «this month», «last 30 days» on the
         * first of the month — and judging a provider against days in the future would mark every such
         * view permanently partial, which is both false and the kind of warning people learn to ignore.
         * Coverage is therefore measured against the last day it is REASONABLE to have figures for.
         *
         * Yesterday, not today: providers close a day before publishing it, and a sync that ran this
         * morning has nothing to say about this afternoon. Expecting today would make every scope
         * partial until tomorrow.
         */
        $expectedThrough = Carbon::yesterday()->startOfDay();
        $to = $to->copy()->startOfDay()->gt($expectedThrough) ? $expectedThrough : $to->copy()->startOfDay();

        $expected = $this->expectedProviders($tenantId, $projectId, $from, $to, $providers);

        if ($expected === []) {
            return AggregateCoverage::complete();
        }

        $reported = $this->providersWithFigures($tenantId, $projectId, $from, $to);
        $runs = $this->latestRuns($tenantId, array_keys($expected));

        $states = [];
        $reasons = [];

        foreach ($expected as $provider => $lifecycle) {
            if (! $lifecycle['active']) {
                $states[$provider] = ContributionState::Inactive;
                $reasons[$provider] = $lifecycle['reason'];

                continue;
            }

            $run = $runs[$provider] ?? null;
            /*
             * Compared as DAYS, deliberately.
             *
             * `window_end` is a date and parses to midnight; a window's `to` routinely arrives as an
             * end-of-day instant. Comparing them directly makes a sync that covered the final day look
             * as though it stopped short of it by 23 hours and 59 minutes — the fractional-window
             * off-by-one, and it marks a fully-synced provider partial every single time.
             */
            $covered = $run === null ? null : Carbon::parse((string) $run->window_end)->startOfDay();
            $reachesWindow = $covered !== null
                && ! $covered->lt($to->copy()->startOfDay()->subDays(self::STALE_TOLERANCE_DAYS));

            if (in_array($provider, $reported, true)) {
                /*
                 * Figures arrived — but figures for HOW MUCH of the window?
                 *
                 * This originally stopped here and called any provider with a row `ReportedValue`, and
                 * a rehearsal against real data caught it: every provider had spend through the 18th
                 * and a sync that covered only to the 18th, against a window ending on the 31st. The
                 * total read «complete» while thirteen days were missing from every contributor — the
                 * premature complete total, arrived at from the opposite direction to the one I was
                 * guarding.
                 *
                 * Presence of a figure proves the provider reported SOMETHING. Only the checkpoint says
                 * whether it reported everything asked for.
                 */
                $states[$provider] = $reachesWindow || $run === null
                    ? ContributionState::ReportedValue
                    : ContributionState::Partial;

                if (! $reachesWindow && $run !== null) {
                    $reasons[$provider] = sprintf(
                        'Reported through %s; this window ends %s.',
                        $covered->toDateString(),
                        $to->toDateString(),
                    );
                }

                continue;
            }

            // Expected, running, and nothing arrived. The evidence decides which absence this is.
            if ($run === null) {
                $states[$provider] = ContributionState::NotReported;
                $reasons[$provider] = 'No sync run has ever covered this provider for this scope.';

                continue;
            }

            if ($run->status === 'failed') {
                $states[$provider] = ContributionState::Failed;
                $reasons[$provider] = 'The last sync failed: '.($run->error ?? 'no error recorded');

                continue;
            }

            if (! $reachesWindow) {
                $states[$provider] = ContributionState::Stale;
                $reasons[$provider] = sprintf(
                    'Synced only through %s; this window ends %s.',
                    $covered->toDateString(),
                    $to->toDateString(),
                );

                continue;
            }

            /*
             * Synced through the window, running, and reported nothing. THIS is the case where an
             * absence is finally an answer: the sync covered these days and the provider had nothing
             * to say about them. Still not a zero — the caller decides whether «no activity» renders
             * as a dash or as 0 — but it does not degrade the total.
             */
            $states[$provider] = ContributionState::NoActivity;
        }

        return new AggregateCoverage($states, $reasons);
    }

    /**
     * Providers with at least one campaign that was ALIVE during the window, and whether it was.
     *
     * Lifecycle is read from the campaign, because that is where a platform stops: an account stays
     * connected long after its last campaign ends, so connection state alone would keep a finished
     * platform permanently «expected» and every total permanently partial.
     *
     * @return array<string, array{active: bool, reason: string}>
     */
    private function expectedProviders(
        string $tenantId,
        ?string $projectId,
        Carbon $from,
        Carbon $to,
        ?array $providers,
    ): array {
        $q = DB::table('external_campaigns')
            ->where('tenant_id', $tenantId)
            ->select('provider', 'status', 'starts_at', 'ends_at');

        if ($projectId !== null) {
            $q->where('project_id', $projectId);
        }
        if ($providers !== null) {
            $q->whereIn('provider', $providers);
        }

        /*
         * SANDBOX-PROD-001 — a row the SANDBOX CONNECTOR wrote is a fixture, not delivery.
         *
         * Production, read project-wide (`integrations:diagnose`, 2026-09-08):
         *
         *     snapchat  89   2 flagged `raw->sandbox`
         *     linkedin  24   0 flagged
         *     meta       4   2 flagged
         *     sandbox    2   2 flagged      <- the whole of it
         *
         * Both rows claiming `sandbox` are flagged, and that flag means one thing: the sandbox
         * connector wrote them. They are synthetic. They never carried a riyal of anybody's money,
         * so they cannot owe a figure, and their silence is not a gap in a client's report. Expecting
         * them made the owner's live report permanently `partial` for a platform that does not exist.
         *
         * ## Two earlier repairs failed, and the shape above says why
         *
         * The first excluded flagged rows under a NON-sandbox account, deliberately sparing «a
         * sandbox account's own fixtures». The second expected only providers the tenant holds an
         * account for. Four unlinked sandbox accounts exist, so each rule preserved exactly these
         * rows, for opposite reasons. Both were written against an inferred shape; this one is
         * written against the printed one.
         *
         * ## Why the flag alone, with no account condition
         *
         * The account was never the point. A fixture is a fixture wherever it is filed — and the
         * account-based conditions are what let these two rows through twice. A demo tenant whose
         * rows are ALL synthetic now reports `complete` rather than expecting a platform to answer
         * for made-up data, which is the honest reading of a demo.
         *
         * Real contributors are untouched, and the numbers above show it: snapchat keeps 87 unflagged
         * rows, meta 2, linkedin 24. Only `sandbox`, which is flagged end to end, leaves.
         */
        $q->whereRaw("COALESCE((raw->'sandbox')::jsonb, 'false'::jsonb) <> 'true'::jsonb");

        $out = [];

        foreach ($q->get() as $row) {
            $provider = (string) $row->provider;

            /*
             * A campaign is alive for this window if it overlaps it at all. `ends_at` before the
             * window opens is the stopped case; `starts_at` after it closes is the not-yet case. Both
             * are absences that cost the total nothing.
             */
            $endedBefore = $row->ends_at !== null && Carbon::parse((string) $row->ends_at)->lt($from);
            $startedAfter = $row->starts_at !== null && Carbon::parse((string) $row->starts_at)->gt($to);
            $alive = ! $endedBefore && ! $startedAfter;

            // One live campaign is enough to make the provider expected; keep the strongest evidence.
            if (($out[$provider]['active'] ?? false) === true) {
                continue;
            }

            $out[$provider] = [
                'active' => $alive,
                'reason' => $endedBefore
                    ? 'Its campaigns ended before this window opened.'
                    : ($startedAfter ? 'Its campaigns start after this window closes.' : ''),
            ];
        }

        return $out;
    }

    /** Providers that actually put figures in this window. Proves presence only — never absence. */
    private function providersWithFigures(string $tenantId, ?string $projectId, Carbon $from, Carbon $to): array
    {
        $q = DB::table('daily_metrics')
            ->where('tenant_id', $tenantId)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);

        if ($projectId !== null) {
            $q->where('project_id', $projectId);
        }

        return $q->distinct()->pluck('provider')->map(static fn ($p): string => (string) $p)->all();
    }

    /**
     * The most recent sync run per provider — the checkpoint that says what was actually covered.
     *
     * @return array<string, object>
     */
    private function latestRuns(string $tenantId, array $providers): array
    {
        if ($providers === []) {
            return [];
        }

        $rows = DB::table('metric_sync_runs')
            ->where('tenant_id', $tenantId)
            ->whereIn('provider', $providers)
            ->orderByDesc('window_end')
            ->orderByDesc('created_at')
            ->get(['provider', 'status', 'window_end', 'error']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->provider] ??= $row;
        }

        return $out;
    }
}
