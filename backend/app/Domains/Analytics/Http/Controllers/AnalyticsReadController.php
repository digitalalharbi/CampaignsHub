<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers;

use App\Domains\Analytics\Services\AnalyticsReads;
use App\Domains\Analytics\Services\InfluencerPerformance;
use App\Domains\Analytics\Services\SiteReadModel;
use App\Domains\Analytics\Support\AnalyticsWindow;
use App\Domains\Metrics\Services\PeriodResolver;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * ANALYTICS-DATA-001 — the read-only analytics sections of ONE project: site (GA4), store, influencers, attribution.
 *
 * Every route here is a GET behind `project.can`, and every section is computed for the route's own tenant and
 * project by {@see AnalyticsReads}: nothing reads another project, and nothing here writes. Reader-specific cuts
 * (sales elements for a reader without the store capability, costs for one without `influencers.view_costs`) are
 * made on the returned copy, after the cache.
 */
final class AnalyticsReadController extends Controller
{
    public function __construct(private readonly AnalyticsReads $reads, private readonly SiteReadModel $site) {}

    public function binding(): JsonResponse
    {
        [$tenantId, $projectId] = $this->scope();

        return ApiResponse::success($this->site->binding($tenantId, $projectId, 'unknown'), 'Website binding.');
    }

    public function site(Request $request): JsonResponse
    {
        [$tenantId, $projectId] = $this->scope();
        [$from, $to, $period] = $this->window($request);
        $payload = $this->reads->site($tenantId, $projectId, $from, $to);

        if (! $this->mayReadSales($request, $projectId)) {
            $payload = $this->withoutSales($payload);
        }

        return ApiResponse::success($payload, 'Site analytics.', meta: ['period' => $period]);
    }

    public function store(Request $request): JsonResponse
    {
        [$tenantId, $projectId] = $this->scope();
        [$from, $to, $period] = $this->window($request);

        return ApiResponse::success($this->reads->store($tenantId, $projectId, $from, $to), 'Store analytics.', meta: ['period' => $period]);
    }

    public function influencers(Request $request): JsonResponse
    {
        [$tenantId, $projectId] = $this->scope();
        [$from, $to, $period] = $this->window($request);
        $payload = $this->reads->influencers($tenantId, $projectId, $from, $to);

        if (! (bool) $request->user()?->hasPermission('influencers.view_costs')) {
            $payload = InfluencerPerformance::withoutCosts($payload);
        }

        return ApiResponse::success($payload, 'Influencer analytics.', meta: ['period' => $period]);
    }

    public function attribution(Request $request): JsonResponse
    {
        [$tenantId, $projectId] = $this->scope();
        [$from, $to, $period] = $this->window($request);

        return ApiResponse::success($this->reads->attribution($tenantId, $projectId, $from, $to), 'Attribution.', meta: ['period' => $period]);
    }

    /**
     * Without the store capability a reader sees the site's traffic, never its sales: no purchases, revenue, carts or
     * checkouts anywhere in the payload, and no reconciliation against the ledger.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function withoutSales(array $p): array
    {
        $drop = ['purchases', 'revenue', 'add_to_carts', 'checkouts', 'key_events', 'conversion_rate'];
        $strip = static fn (array $rows): array => array_map(static fn (array $r): array => array_diff_key($r, array_flip($drop)), $rows);
        foreach (['channels', 'devices', 'countries', 'pages'] as $k) {
            $p[$k] = $strip($p[$k] ?? []);
        }
        $p['funnel'] = array_values(array_filter($p['funnel'] ?? [], static fn (array $s): bool => $s['stage'] === 'visit'));
        $p['reconciliation'] = null;
        $p['sales_hidden'] = true;

        return $p;
    }

    private function mayReadSales(Request $request, string $projectId): bool
    {
        $user = $request->user();

        return $user !== null && app(ProjectAbilities::class)->allows($user, $projectId, ProjectCapability::ANALYTICS_STORE_VIEW);
    }

    /** @return array{0: string, 1: string} */
    private function scope(): array
    {
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();
        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        return [$tenantId, $projectId];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: array<string, mixed>} */
    private function window(Request $request): array
    {
        $v = $request->validate([
            'period' => ['sometimes', 'string', 'in:'.implode(',', PeriodResolver::PRESETS)],
            'from' => ['sometimes', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'required_with:from'],
        ]);

        try {
            return AnalyticsWindow::resolve($v);
        } catch (InvalidArgumentException) {
            abort(422, 'Unknown period.');
        }
    }
}
