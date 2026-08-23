<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Metrics\Models\MetricSyncRun;
use Illuminate\Support\Collection;

/**
 * CONTENT-STATE-SEMANTICS-001 — why a creative has no numbers, told apart rather than lumped together.
 *
 * ## The four states, and why one sentence for all of them is wrong
 *
 * The Content Library said «لا توجد بيانات» under every creative without figures. That single
 * sentence was covering four situations an operator would act on differently:
 *
 *   - **unsupported** — this provider has no creative-level reporting. Nothing is missing and
 *     nothing will ever arrive; the number does not exist to be fetched.
 *   - **failed** — we asked and the call failed. Numbers exist at the platform and we do not have
 *     them. This is a pipeline problem, and «no data» actively disguises it as an idle campaign.
 *   - **did not run** — the fetch succeeded and this creative simply had no delivery in the window.
 *     Nothing is wrong. This is the only one of the four that «no data» ever meant.
 *   - **reported** — it delivered, and the figures are real.
 *
 * ## Read from the sync, never inferred from zeros
 *
 * The answer comes from `metric_sync_runs.creative_status`, written by `AccountMetricsSyncer` at the
 * moment it knew. A frontend looking at an empty metrics object cannot tell any of these apart, and
 * guessing from an absent value is how «the platform is down» and «the ad is paused» became the same
 * screen.
 *
 * Scoped per PROVIDER because that is the grain of the truth: Snapchat can be reporting happily
 * while TikTok has never supported creative stats at all, and one library page shows both.
 */
final class CreativeMetricsAvailability
{
    /**
     * The most recent creative-fetch outcome for each provider these creatives belong to.
     *
     * @param  Collection<int, ExternalCreative>  $creatives
     * @return array<string, array{status:string, rows:?int, error:?string, at:?string}>
     */
    public function forCreatives(mixed $creatives): array
    {
        $providers = $creatives->pluck('provider')->filter()->unique()->values()->all();
        $projectIds = $creatives->pluck('project_id')->filter()->unique()->values()->all();

        if ($providers === [] || $projectIds === []) {
            return [];
        }

        /*
         * The LATEST run per provider, not an aggregate over history.
         *
         * A provider that failed yesterday and succeeded an hour ago is working. Folding the two
         * would report a problem that has already been fixed, and an operator who is told about a
         * resolved outage stops believing the next one.
         */
        $runs = MetricSyncRun::withoutGlobalScopes()
            ->whereIn('project_id', $projectIds)
            ->whereIn('provider', $providers)
            ->whereNotNull('creative_status')
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->get(['provider', 'creative_status', 'creative_rows', 'creative_error', 'finished_at']);

        $out = [];

        foreach ($runs as $run) {
            $provider = (string) $run->provider;

            // First wins: the collection is already ordered newest-first.
            if (isset($out[$provider])) {
                continue;
            }

            $out[$provider] = [
                'status' => (string) $run->creative_status,
                'rows' => $run->creative_rows === null ? null : (int) $run->creative_rows,
                'error' => $run->creative_error === null ? null : (string) $run->creative_error,
                'at' => $run->finished_at?->toIso8601String(),
            ];
        }

        /*
         * A provider with no recorded attempt at all is «unknown», not «unsupported».
         *
         * Runs written before this was recorded have a null status, and a project whose sync has
         * never run has no row at all. Claiming either is unsupported would state a fact about the
         * provider that we have not established.
         */
        foreach ($providers as $provider) {
            $out[(string) $provider] ??= ['status' => 'unknown', 'rows' => null, 'error' => null, 'at' => null];
        }

        return $out;
    }
}
