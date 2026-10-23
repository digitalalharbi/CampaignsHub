<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Launch;

use App\Domains\Forms\Models\ConversionDestination;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderDefinition;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Metrics\Models\EntityDailyMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-READINESS-001 — what each ad account has been SEEN to measure, before a launch asks it to optimise for something.
 *
 * Nothing here calls a platform. The only evidence is what the synced results already hold for the account's own ad sets
 * and ads: an event the platform reported with a figure above zero in the window was observed; an event it never reported,
 * or reported as nothing, was not. «The pixel exists» cannot be read from any official API this product uses, so it is
 * never claimed — `pixel` says `unavailable` and names why, while the four events say what they were seen to do.
 *
 * Per event, three states:
 *   - `unavailable` — never reported in the window (no figure, or only zeros). Nothing is assumed about why.
 *   - `proven`      — reported above zero, but under the volume an optimiser needs to learn from.
 *   - `eligible`    — proven AND at or above that volume (config `launch.readiness.eligible_events`, per window).
 *
 * Per account, a tier the journey planner reads: `sales_ready` (purchase eligible), `partial` (purchase proven, or a
 * lower-funnel event eligible), `none`. Demo rows are never evidence.
 */
final class LaunchReadiness
{
    public const EVENTS = ['page_views' => 'PageView', 'add_to_cart' => 'AddToCart', 'leads' => 'Lead', 'purchases' => 'Purchase'];

    public const UNAVAILABLE = 'unavailable';

    public const PROVEN = 'proven';

    public const ELIGIBLE = 'eligible';

    /**
     * @param  list<array<string,mixed>>  $accounts  rows from CampaignWriteService::accountOptions (id, provider, …)
     * @return array{window_days:int, eligible_events:int, accounts:array<string,array<string,mixed>>, project:array<string,mixed>}
     */
    public function forAccounts(string $tenantId, string $projectId, array $accounts, ?Carbon $now = null): array
    {
        $days = max(1, (int) config('launch.readiness.window_days', 30));
        $threshold = max(1, (int) config('launch.readiness.eligible_events', 50));
        $since = ($now ?? Carbon::now())->copy()->subDays($days)->toDateString();

        $sums = EntityDailyMetric::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('is_demo', false)
            ->where('metric_date', '>=', $since)
            ->whereIn('external_account_id', array_column($accounts, 'id') ?: ['-'])
            ->groupBy('external_account_id')
            ->selectRaw('external_account_id, sum(page_views) as page_views, sum(add_to_cart) as add_to_cart, sum(leads) as leads, sum(purchases) as purchases')
            ->get()
            ->keyBy(fn ($r) => (string) $r->external_account_id);

        $out = [];
        foreach ($accounts as $account) {
            $row = $sums->get((string) $account['id']);
            $events = [];
            foreach (self::EVENTS as $column => $label) {
                $count = $row === null || $row->{$column} === null ? 0 : (int) round((float) $row->{$column});
                $events[$column] = [
                    'event' => $label,
                    'status' => $count <= 0 ? self::UNAVAILABLE : ($count >= $threshold ? self::ELIGIBLE : self::PROVEN),
                    'observed' => $count,
                ];
            }

            $out[(string) $account['id']] = [
                'provider' => $account['provider'] ?? null,
                'events' => $events,
                'pixel' => ['status' => self::UNAVAILABLE, 'reason' => 'not_readable_from_platform_api'],
                'tier' => self::tier($events),
            ];
        }

        return [
            'window_days' => $days,
            'eligible_events' => $threshold,
            'accounts' => $out,
            'project' => $this->project($tenantId, $projectId),
        ];
    }

    /** @param array<string,array{status:string}> $events */
    public static function tier(array $events): string
    {
        $purchase = $events['purchases']['status'] ?? self::UNAVAILABLE;
        if ($purchase === self::ELIGIBLE) {
            return 'sales_ready';
        }
        if ($purchase === self::PROVEN
            || ($events['leads']['status'] ?? '') === self::ELIGIBLE
            || ($events['add_to_cart']['status'] ?? '') === self::ELIGIBLE) {
            return 'partial';
        }

        return 'none';
    }

    /**
     * The project-level signals that sit beside the account's events: a store, a GA4 property, and the live server-side
     * destinations the Forms Hub holds. Each is a fact about a binding or a record, not about whether it works.
     *
     * @return array<string,mixed>
     */
    private function project(string $tenantId, string $projectId): array
    {
        $kindOf = static fn (ProviderKind $k): array => array_map(static fn (ProviderDefinition $d): string => $d->key, ProviderCatalogue::ofKind($k));

        $bound = DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->where('b.project_id', $projectId)->where('b.is_active', true)
            ->pluck('a.provider')->map(fn ($p) => (string) $p)->unique()->values()->all();

        $live = ConversionDestination::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('status', ConversionDestination::LIVE)
            ->pluck('kind')->unique()->values()->all();

        return [
            'store_linked' => array_values(array_intersect($bound, $kindOf(ProviderKind::Commerce))) !== [],
            'ga4_linked' => array_values(array_intersect($bound, $kindOf(ProviderKind::Measurement))) !== [],
            'live_conversion_destinations' => $live,
        ];
    }
}
