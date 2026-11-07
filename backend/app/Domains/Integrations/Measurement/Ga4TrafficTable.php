<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Reports\Support\ReportReadMemo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TRAFFIC-DRILLDOWN-001 / TABLE-SERVER-PAGING-001 — one breakdown as a server-paged, sortable,
 * filterable table, with the scope in the query.
 *
 * Scope: tenant + project + the project's own selected property + breakdown + window, all in the
 * WHERE. A project with no selected property gets an empty, `not_connected` answer, never another
 * project's rows and never all data. Breakdown, sort and filter keys are whitelisted; values are
 * bound parameters.
 *
 * Drill-down is a chain of exact filters over the stored two-dimension breakdowns:
 * channel (default_channel_group) -> source/medium (channel_source, where_d1 = channel)
 * -> campaign (campaign, where_d2 = source/medium) -> landing page (campaign_landing, where_d1 = campaign).
 * Each level reads its own stored breakdown; levels are not joined into one four-way row.
 */
final class Ga4TrafficTable
{
    public const PAGE_SIZES = [10, 25, 50];

    public const BREAKDOWNS = [
        'source_medium', 'first_user_source_medium', 'default_channel_group', 'channel_source',
        'campaign', 'utm_content', 'utm_term', 'landing_page', 'campaign_landing',
        // Two views of stored rows, not breakdowns of their own: nothing extra is asked of GA4.
        'referrals', 'traffic_class',
    ];

    /** GA4's own default channel groups, folded into the split the Owner reads. Anything else is `other`. */
    private const TRAFFIC_CLASS_SQL = "CASE
        WHEN dimension_1 IN ('Paid Search', 'Paid Social', 'Paid Video', 'Paid Shopping', 'Paid Other', 'Display', 'Cross-network', 'Audio') THEN 'paid'
        WHEN dimension_1 IN ('Organic Search', 'Organic Social', 'Organic Video', 'Organic Shopping') THEN 'organic'
        WHEN dimension_1 = 'Direct' THEN 'direct'
        WHEN dimension_1 = 'Referral' THEN 'referral'
        ELSE 'other' END";

    /** sort key => SQL on the aggregated subquery (whitelist; never user text). */
    private const SORTS = [
        'dimension_1' => 'dimension_1', 'dimension_2' => 'dimension_2', 'sessions' => 'sessions',
        'engaged_sessions' => 'engaged_sessions', 'key_events' => 'key_events', 'add_to_carts' => 'add_to_carts',
        'checkouts' => 'checkouts', 'purchases' => 'purchases', 'revenue' => 'revenue',
        'engagement_rate' => 'engagement_rate', 'conversion_rate' => 'conversion_rate',
    ];

    private const SUMS = ['sessions', 'engaged_sessions', 'key_events', 'add_to_carts', 'checkouts', 'purchases', 'revenue'];

    /**
     * @param  array{breakdown: string, page?: int, per_page?: int, sort?: string, dir?: string, q?: string, where_d1?: ?string, where_d2?: ?string}  $o
     * @return array<string, mixed>
     */
    public function page(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $o, bool $all = false): array
    {
        $breakdown = $o['breakdown'];
        $sort = $o['sort'] ?? 'sessions';
        $dir = strtolower($o['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        if (! in_array($breakdown, self::BREAKDOWNS, true)) {
            throw new InvalidArgumentException('Unknown breakdown.');
        }
        if (! array_key_exists($sort, self::SORTS)) {
            throw new InvalidArgumentException('Unknown sort column.');
        }
        $perPage = in_array((int) ($o['per_page'] ?? 10), self::PAGE_SIZES, true) ? (int) ($o['per_page'] ?? 10) : 10;
        $page = max(1, (int) ($o['page'] ?? 1));

        $property = $this->property($tenantId, $projectId);
        $base = [
            'breakdown' => $breakdown, 'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'page' => $page, 'per_page' => $perPage, 'sort' => $sort, 'dir' => $dir,
        ];
        if ($property === null) {
            $connected = ExternalAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->exists();

            return $base + ['state' => $connected ? 'not_selected' : 'not_connected', 'rows' => [], 'total' => 0, 'pages' => 0, 'currency' => null];
        }

        $q = DB::table('measurement_dimension_rows')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('external_account_id', $property->getKey())
            ->where('breakdown', match ($breakdown) {
                'referrals' => 'source_medium',
                'traffic_class' => 'default_channel_group',
                default => $breakdown,
            })
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);
        if ($breakdown === 'referrals') {
            $q->whereRaw('lower(dimension_2) = ?', ['referral']);
        }
        if (isset($o['where_d1']) && $o['where_d1'] !== '') {
            $q->where('dimension_1', $o['where_d1']);
        }
        if (isset($o['where_d2']) && $o['where_d2'] !== '') {
            $q->where('dimension_2', $o['where_d2']);
        }
        $term = trim((string) ($o['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w->where('dimension_1', 'ilike', $like)->orWhere('dimension_2', 'ilike', $like));
        }

        $split = $breakdown === 'traffic_class';
        $select = $split ? [DB::raw(self::TRAFFIC_CLASS_SQL.' AS dimension_1'), DB::raw("'' AS dimension_2")] : ['dimension_1', 'dimension_2'];
        foreach (self::SUMS as $k) {
            $select[] = DB::raw("SUM(NULLIF(metrics->>'$k','')::float8) AS $k");
        }
        $select[] = DB::raw('MAX(currency) AS currency');
        $grouped = $q->select($select);
        $split ? $grouped->groupByRaw(self::TRAFFIC_CLASS_SQL) : $grouped->groupBy('dimension_1', 'dimension_2');

        $outer = DB::query()->fromSub($grouped, 'g')->select([
            'g.*',
            DB::raw('CASE WHEN sessions > 0 AND engaged_sessions IS NOT NULL THEN engaged_sessions / sessions END AS engagement_rate'),
            DB::raw('CASE WHEN sessions > 0 AND purchases IS NOT NULL THEN purchases / sessions END AS conversion_rate'),
        ]);
        $total = DB::query()->fromSub($grouped, 'c')->count();

        // Absent sorts last in both directions; ties break on the names so pages never overlap.
        $outer->orderByRaw(self::SORTS[$sort].' '.$dir.' NULLS LAST')->orderBy('dimension_1')->orderBy('dimension_2');
        if (! $all) {
            $outer->offset(($page - 1) * $perPage)->limit($perPage);
        }

        $rows = $outer->get()->map(function (object $r): array {
            $out = ['dimension_1' => (string) $r->dimension_1, 'dimension_2' => $r->dimension_2 !== '' ? (string) $r->dimension_2 : null];
            foreach (self::SUMS as $k) {
                $out[$k] = $r->$k === null ? null : round((float) $r->$k, 2);
            }
            $out['engagement_rate'] = $r->engagement_rate === null ? null : round((float) $r->engagement_rate, 4);
            $out['conversion_rate'] = $r->conversion_rate === null ? null : round((float) $r->conversion_rate, 4);

            return $out;
        })->all();

        return $base + [
            'state' => 'ready', 'rows' => $rows, 'total' => $total, 'pages' => (int) max(1, ceil($total / $perPage)),
            'currency' => $property->currency, 'last_synced_at' => $property->last_synced_at?->toIso8601String(),
        ];
    }

    private function property(string $tenantId, string $projectId): ?ExternalAccount
    {
        // PERF-RECOVERY-001 — one read per build: the site, the store reading and the traffic split all ask it.
        $accountId = ReportReadMemo::remember('ga4-binding|'.$tenantId.'|'.$projectId, static fn (): mixed => ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('is_active', true)->where('provider', 'ga4')
            ->orderBy('created_at')->value('external_account_id'));
        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        return ExternalAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->find($accountId);
    }
}
