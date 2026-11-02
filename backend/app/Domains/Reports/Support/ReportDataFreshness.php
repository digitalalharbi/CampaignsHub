<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REPORT-FRESHNESS-STAMP-001 — when a report's data was LAST CONFIRMED by a finished sync, per platform.
 *
 * ## Why this exists
 *
 * A client questioned Snapchat spend on a public live report. «Live» only says THIS system recomputed
 * the page; it says nothing about when the platform last delivered. A report must never imply data is
 * fresh or confirmed when it is not, so each platform carries the completion time of its latest
 * SUCCESSFUL sync run — `finished_at`, never `started_at`/`created_at`/the request time — and a status
 * derived from it.
 *
 * ## Scope (strictly the one `ContributorCoverage::latestRuns` applies)
 *
 * Only runs of the link's own project, only while the run's account is still actively bound to it
 * (`BoundAccountVisibility`), and, for a link narrowed to specific ad accounts, only those accounts.
 * A second project's newer run, or a deselected account's run, can never make this link look fresh.
 *
 * ## One query
 *
 * Every provider is answered by ONE grouped read of `metric_sync_runs`; nothing here queries per
 * provider or per day.
 *
 * ## Status vocabulary
 *
 *   fresh    last success within 90 minutes
 *   delayed  last success 90 minutes to 24 hours ago, latest attempt did not fail
 *   stale    last success more than 24 hours ago, latest attempt did not fail
 *   failed   latest attempt failed and the last success (if any) is older than 90 minutes
 *   unknown  no run at all (or only an unfinished one) — we cannot vouch for anything
 *
 * `partial_mapping`, `awaiting_assignment` and `failed` runs are NOT successes (part of what was asked
 * for did not land); `success` and `no_data` are (we asked and the platform answered).
 */
final class ReportDataFreshness
{
    public const FRESH_MINUTES = 90;

    public const STALE_MINUTES = 1440;

    public const TIMEZONE = 'Asia/Riyadh';

    /** Worst first. */
    private const SEVERITY = ['failed' => 5, 'stale' => 4, 'unknown' => 3, 'delayed' => 2, 'fresh' => 1];

    /**
     * @param  list<string>  $providers
     * @param  list<string>|null  $accountIds  the link's ad-account ceiling; null/empty = every still-bound account of the project
     * @return array<string, array{last_success_at: ?string, last_attempt_at: ?string, last_attempt_status: string, age_minutes: ?int, data_status: string}>
     */
    public function forProviders(string $tenantId, string $projectId, array $providers, ?array $accountIds, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $providers = array_values(array_unique(array_filter($providers)));
        if ($projectId === '' || $providers === []) {
            return [];
        }

        $rows = DB::table('metric_sync_runs')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->whereIn('provider', $providers)
            ->when($accountIds !== null && $accountIds !== [], fn ($q) => $q->whereIn('external_account_id', $accountIds))
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'metric_sync_runs'))
            ->select('provider')
            ->selectRaw("MAX(finished_at) FILTER (WHERE status IN ('success', 'no_data')) AS last_success_at")
            ->selectRaw("MAX(COALESCE(finished_at, started_at, created_at)) FILTER (WHERE status IN ('failed', 'partial_mapping', 'awaiting_assignment')) AS last_failed_at")
            ->selectRaw("MAX(COALESCE(started_at, created_at)) FILTER (WHERE status IN ('running', 'pending')) AS last_running_at")
            ->groupBy('provider')
            ->get()
            ->keyBy(fn ($r): string => (string) $r->provider);

        $out = [];
        foreach ($providers as $provider) {
            $out[$provider] = $this->entry($rows->get($provider), $now);
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries  payload `freshness` rows (already stamped)
     * @return array{oldest_success_at: ?string, worst_status: string, timezone: string, generated_at: string}
     */
    public function summary(array $entries, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $stamped = array_values(array_filter($entries, static fn (array $e): bool => ($e['data_status'] ?? null) !== null));

        $oldest = null;
        $worst = 'unknown';
        $rank = 0;
        foreach ($stamped as $e) {
            $at = $e['last_success_at'] ?? null;
            if ($at !== null && ($oldest === null || $at < $oldest)) {
                $oldest = $at;
            }
            $r = self::SEVERITY[$e['data_status']] ?? 3;
            if ($r > $rank) {
                $rank = $r;
                $worst = (string) $e['data_status'];
            }
        }

        return [
            'oldest_success_at' => $oldest,
            'worst_status' => $worst,
            'timezone' => self::TIMEZONE,
            'generated_at' => $now->copy()->utc()->toIso8601ZuluString(),
        ];
    }

    /** @return array{last_success_at: ?string, last_attempt_at: ?string, last_attempt_status: string, age_minutes: ?int, data_status: string} */
    private function entry(?object $row, Carbon $now): array
    {
        $success = $row?->last_success_at ? Carbon::parse((string) $row->last_success_at)->utc() : null;
        $failed = $row?->last_failed_at ? Carbon::parse((string) $row->last_failed_at)->utc() : null;
        $running = $row?->last_running_at ? Carbon::parse((string) $row->last_running_at)->utc() : null;

        $attempts = array_filter(['success' => $success, 'failed' => $failed, 'running' => $running]);
        $attemptStatus = 'none';
        $attemptAt = null;
        foreach ($attempts as $status => $at) {
            // Strictly later wins; on a tie a success is preferred (it was listed first).
            if ($attemptAt === null || $at->gt($attemptAt)) {
                $attemptAt = $at;
                $attemptStatus = $status;
            }
        }

        $age = $success === null ? null : max(0, (int) floor($success->diffInSeconds($now, false) / 60));

        $status = match (true) {
            $success === null => $attemptStatus === 'failed' ? 'failed' : 'unknown',
            $age <= self::FRESH_MINUTES => 'fresh',
            $attemptStatus === 'failed' => 'failed',
            $age <= self::STALE_MINUTES => 'delayed',
            default => 'stale',
        };

        return [
            'last_success_at' => $success?->toIso8601ZuluString(),
            'last_attempt_at' => $attemptAt?->toIso8601ZuluString(),
            'last_attempt_status' => $attemptStatus,
            'age_minutes' => $age,
            'data_status' => $status,
        ];
    }
}
