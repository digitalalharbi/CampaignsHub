<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Support\Reconciliation;
use App\Domains\Analytics\Support\Source;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4SiteAnalytics;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Metrics\Services\AttributionTransparency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ANALYTICS-DATA-001 §4 — the site, as the project's GA4 property measured it, in one read.
 *
 * GA4 is its own source (a third ProviderKind, never an ad platform): nothing here is added to a platform's or the
 * store's figure, and the reconciliation sets the three beside each other with `summed: false`.
 *
 * ## Binding, honestly
 *
 * A property is bound to a project by a person confirming it (DISCOVERED is not SELECTED: an agency's Google sign-in
 * reaches other clients' properties, and syncing what it can see would pull one client's site into another's
 * project). So the read says where the project stands — `bound`, `awaiting_selection` (a Google sign-in exists, no
 * property is confirmed for this project) or `awaiting_credentials` (no sign-in at all) — and, when exactly ONE
 * property was discovered in the whole tenant and none is bound anywhere, offers it as a `suggestion` that still
 * needs a confirmation. It never binds by itself and it never invents a figure.
 */
final class SiteReadModel
{
    private const TOP = 10;

    /** KPI key in GA4's own names → the key the board shows. `visits` is GA4's session count under its older name. */
    private const KPIS = [
        'visits' => 'sessions',
        'users' => 'users_daily_sum',
        'sessions' => 'sessions',
        'avg_duration_seconds' => 'avg_session_seconds',
        'bounce_rate' => 'bounce_rate',
        'engagement_rate' => 'engagement_rate',
    ];

    public function __construct(
        private readonly Ga4SiteAnalytics $site,
        private readonly AttributionTransparency $transparency,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $raw = $this->site->build($tenantId, $projectId, $from, $to);
        $binding = $this->binding($tenantId, $projectId, $raw['state'] ?? 'not_connected');
        $base = [
            'section' => 'site',
            'source' => Source::GA4,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'binding' => $binding,
            'blended_with_other_sources' => false,
        ];

        if (($raw['state'] ?? null) !== 'ready') {
            return $base + [
                'state' => ($raw['state'] ?? null) === 'empty' ? 'empty' : 'not_linked',
                'kpis' => [], 'channels' => [], 'devices' => [], 'countries' => [], 'pages' => [], 'funnel' => [], 'reconciliation' => null,
            ];
        }

        $byKey = [];
        foreach ($raw['kpis'] as $k) {
            $byKey[$k['key']] = $k;
        }
        $kpis = [];
        foreach (self::KPIS as $name => $key) {
            $k = $byKey[$key] ?? null;
            $kpis[$name] = $k === null
                ? Source::of(null, Source::GA4, ['previous' => null, 'change_pct' => null])
                : Source::of($k['current'], Source::GA4, [
                    'previous' => $k['previous'],
                    'change_pct' => $k['change_pct'],
                    'kind' => $k['kind'],
                    'definition' => $k['definition'],
                    'trend' => $k['trend'],
                    'alias_of' => $name === 'visits' ? 'sessions' : null,
                ]);
        }

        return $base + [
            'state' => 'ready',
            'property' => $raw['property'],
            'last_synced_at' => $raw['last_synced_at'],
            'kpis' => $kpis,
            'channels' => $this->channels($tenantId, $projectId, $raw, $from, $to),
            'devices' => $this->rows($raw['breakdowns']['device'] ?? null, self::TOP),
            'countries' => $this->rows($raw['breakdowns']['country'] ?? null, self::TOP),
            'pages' => $this->rows($raw['breakdowns']['landing_page'] ?? null, self::TOP),
            'funnel' => $this->funnel($raw['funnel'] ?? []),
            'reconciliation' => $this->reconciliation($tenantId, $projectId, $from, $to),
        ];
    }

    /**
     * @return array{state: string, status: string, property: ?array<string, mixed>, last_synced_at: ?string, suggestion: ?array<string, mixed>, auto_bind: string}
     */
    public function binding(string $tenantId, string $projectId, string $siteState): array
    {
        $property = $this->site->property($tenantId, $projectId);
        if ($property !== null) {
            return [
                'state' => 'bound', 'status' => 'BOUND',
                'property' => ['id' => (string) $property->external_id, 'name' => $property->name],
                'last_synced_at' => $property->last_synced_at?->toIso8601String(),
                'suggestion' => null, 'auto_bind' => 'confirmed',
            ];
        }

        $discovered = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)
            ->get(['id', 'external_id', 'name']);

        if ($discovered->isEmpty()) {
            return ['state' => 'awaiting_credentials', 'status' => 'AWAITING_CREDENTIALS', 'property' => null, 'last_synced_at' => null, 'suggestion' => null, 'auto_bind' => 'needs_google_sign_in'];
        }

        $boundAnywhere = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('provider', 'ga4')->where('is_active', true)->exists();
        $only = $discovered->count() === 1 && ! $boundAnywhere ? $discovered->first() : null;

        return [
            'state' => 'awaiting_selection', 'status' => 'AWAITING_SELECTION', 'property' => null, 'last_synced_at' => null,
            'suggestion' => $only === null ? null : ['property_id' => (string) $only->external_id, 'name' => $only->name],
            'auto_bind' => 'needs_confirmation',
        ];
    }

    /**
     * GA4's own channel groups when the property returned them, else its source / medium rows.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private function channels(string $tenantId, string $projectId, array $raw, Carbon $from, Carbon $to, bool $fallback = true): array
    {
        $account = $this->site->property($tenantId, $projectId);
        $rows = $account === null ? collect() : DB::table('measurement_dimension_rows')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $account->getKey())
            ->where('breakdown', 'default_channel_group')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->get(['dimension_1', 'metrics']);

        if ($rows->isEmpty()) {
            if (! $fallback) {
                return [];
            }

            return $this->rows($raw['breakdowns']['source_medium'] ?? null, self::TOP, 'source_medium');
        }

        $agg = [];
        foreach ($rows as $r) {
            $m = (array) json_decode((string) $r->metrics, true);
            $a = $agg[(string) $r->dimension_1] ?? ['sessions' => 0.0, 'engaged_sessions' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0];
            foreach (array_keys($a) as $k) {
                $a[$k] += (float) ($m[$k] ?? 0);
            }
            $agg[(string) $r->dimension_1] = $a;
        }
        $total = array_sum(array_column($agg, 'sessions'));
        $out = [];
        foreach ($agg as $name => $a) {
            $out[] = ['channel' => $name, 'source' => Source::GA4]
                + array_map(static fn (float $v): float => round($v, 2), $a)
                + ['share_of_sessions' => $total > 0 ? round($a['sessions'] / $total, 4) : null];
        }
        usort($out, static fn (array $x, array $y): int => $y['sessions'] <=> $x['sessions']);

        return array_slice($out, 0, $fallback ? self::TOP : 50);
    }

    /**
     * @param  ?array{rows: list<array<string, mixed>>, more: int}  $table
     * @return list<array<string, mixed>>
     */
    private function rows(?array $table, int $limit, ?string $kind = null): array
    {
        if ($table === null) {
            return [];
        }

        return array_map(
            static fn (array $r): array => $r + ['source' => Source::GA4] + ($kind === null ? [] : ['kind' => $kind]),
            array_slice($table['rows'], 0, $limit),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function funnel(array $steps): array
    {
        $names = ['sessions' => 'visit', 'add_to_carts' => 'cart', 'checkouts' => 'checkout', 'purchases' => 'purchase'];

        return array_map(static fn (array $s): array => [
            'stage' => $names[$s['stage']] ?? $s['stage'],
            'count' => $s['count'],
            'step_rate' => $s['step_rate'],
            'exceeds_previous' => $s['exceeds_previous'],
            'source' => Source::GA4,
        ], $steps);
    }

    /** @return array<string, mixed> */
    private function reconciliation(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        return Reconciliation::from($this->transparency->threeSources($tenantId, $projectId, $from, $to));
    }

    /**
     * GA4's session channel groups for the window, one row each; empty when the property returned none.
     *
     * @return list<array<string, mixed>>
     */
    public function channelGroups(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        return $this->channels($tenantId, $projectId, [], $from, $to, false);
    }
}
