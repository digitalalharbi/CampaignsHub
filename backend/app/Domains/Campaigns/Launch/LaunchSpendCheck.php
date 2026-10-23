<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Launch;

use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\Services\SpendLimitGovernor;
use Illuminate\Support\Carbon;

/**
 * LAUNCH-SPEND-LIMIT-001 — the project's own spend limits, read against what a launch would commit, BEFORE the person approves it.
 *
 * It WARNS, it does not block, and every row says so: a spend limit here is internal monitoring (`SpendLimit::ENFORCEMENT`) — this
 * product cannot stop a platform from spending, and pretending a limit gates a launch would be the false safety the governor
 * itself refuses to state. What it can do is put the arithmetic in front of the person at the moment they approve:
 *
 *     remaining under the limit  −  (daily commitment of the accounts the limit covers × days left in the period)
 *
 * Only commitments in the limit's own currency are added; a line in another currency is not converted behind the reader's back, it
 * makes that limit `not_comparable`. A limit with no comparable spend so far (the governor's `unknown`) is `not_comparable` too.
 */
final class LaunchSpendCheck
{
    public function __construct(private readonly SpendLimitGovernor $governor) {}

    /**
     * @param  list<array{account_id:string, provider:string, daily_budget:float|null, currency:string}>  $lines
     * @return list<array<string,mixed>>
     */
    public function forProject(string $projectId, array $lines, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $limits = SpendLimit::query()
            ->where('project_id', $projectId)
            ->where('active', true)
            ->whereDate('starts_on', '<=', $today->toDateString())
            ->whereDate('ends_on', '>=', $today->toDateString())
            ->get();

        $out = [];
        foreach ($limits as $limit) {
            if ($limit->scope === SpendLimitScope::Campaign) {
                continue;   // a campaign that does not exist yet cannot already be inside a campaign limit
            }
            $covered = array_values(array_filter($lines, fn (array $l): bool => $l['daily_budget'] !== null && match ($limit->scope) {
                SpendLimitScope::Project => true,
                SpendLimitScope::Platform => $l['provider'] === (string) $limit->scope_id,
                default => $l['account_id'] === (string) $limit->scope_id,   // Account — Campaign was set aside above
            }));
            if ($covered === []) {
                continue;
            }
            $read = $this->governor->read($limit, $today);
            $daysLeft = (int) max(1, $today->diffInDays($limit->ends_on->copy()->startOfDay()) + 1);
            $sameCurrency = array_filter($covered, fn (array $l): bool => strtoupper($l['currency']) === strtoupper((string) $limit->currency));
            $daily = round(array_sum(array_map(fn (array $l): float => (float) $l['daily_budget'], $sameCurrency)), 2);
            $comparable = count($sameCurrency) === count($covered) && $read['remaining'] !== null;
            $committed = round($daily * $daysLeft, 2);
            $over = $comparable ? round($committed - (float) $read['remaining'], 2) : null;

            $out[] = [
                'limit_id' => (string) $limit->getKey(),
                'scope' => $limit->scope->value,
                'scope_id' => $limit->scope_id,
                'currency' => (string) $limit->currency,
                'period_ends_on' => $limit->ends_on->toDateString(),
                'days_left' => $daysLeft,
                'remaining' => $read['remaining'],
                'daily_commitment' => $daily,
                'committed_until_period_end' => $committed,
                'over_by' => $over !== null && $over > 0 ? $over : null,
                'status' => ! $comparable ? 'not_comparable' : ($over > 0 ? 'exceeds' : 'within'),
                'enforcement' => SpendLimit::ENFORCEMENT,
            ];
        }

        return $out;
    }
}
