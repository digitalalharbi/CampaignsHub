<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Http\Controllers;

use App\Domains\Influencers\Services\InfluencerImpact;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\TabularDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** INFLUENCER-IMPACT-001 — behind `portal:influencers`, so INFL-OFF-001 closes it with the rest of the service. */
final class ImpactController extends Controller
{
    public function __construct(
        private readonly InfluencerImpact $impact,
        private readonly ClientScopeResolver $scopes,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->read($request), 'Creator impact.');
    }

    /** Every creator in scope; the page and page size are ignored on purpose. */
    public function export(Request $request): StreamedResponse
    {
        $data = $this->read($request, all: true);
        $head = ['Creator', 'Handle', 'Links', 'Codes', 'Clicks (since issued)', 'Store orders by coupon', 'Confirmed', 'Overlap with ads', 'Net revenue', 'Currency', 'Cost', 'Cost currency', 'CPA', 'ROAS', 'ROI'];
        $keys = ['name', 'handle', 'links', 'codes', 'clicks', 'orders', 'confirmed', 'overlap_orders', 'net_revenue', 'currency', 'cost', 'cost_currency', 'cpa', 'roas', 'roi'];
        $lines = array_map(static fn (array $r): array => array_map(static fn (string $k): mixed => $r[$k] ?? null, $keys), $data['rows']);

        return TabularDownload::stream('creator-impact-'.$data['period']['from'].'_'.$data['period']['to'], $head, $lines, (string) $request->query('format', 'csv'));
    }

    /** @return array<string, mixed> */
    private function read(Request $request, bool $all = false): array
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.view'), 403);

        $v = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', InfluencerImpact::SORTS)],
            'dir' => ['sometimes', 'string', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $to = isset($v['to']) ? Carbon::parse($v['to'])->endOfDay() : Carbon::now()->endOfDay();
        $from = isset($v['from']) ? Carbon::parse($v['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        $ids = $this->scopes->reachableClientIds($user);

        return $this->impact->build((string) $this->tenant->tenantId(), $ids === null ? null : array_values(array_map('strval', (array) $ids)), (bool) $user->hasPermission('influencers.view_costs'), $from, $to, $v, $all);
    }
}
