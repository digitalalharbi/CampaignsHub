<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Commerce\Services\OrderSourceEvidence;
use App\Domains\Commerce\Services\StoreDepth;
use App\Domains\Commerce\Services\StoreOrderSources;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\TabularDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** STORE-ANALYTICS-DEPTH-001 — the store's own reading, and each order tied to its source by evidence. */
final class StoreAnalyticsController extends Controller
{
    public function __construct(
        private readonly StoreOrderSources $sources,
        private readonly StoreDepth $depth,
        private readonly ProjectContext $project,
        private readonly TenantContext $tenant,
    ) {}

    public function depth(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return ApiResponse::success($this->depth->build($this->tenantId(), $this->projectId(), $from, $to), 'Store depth.');
    }

    public function orders(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return ApiResponse::success($this->sources->orders($this->tenantId(), $this->projectId(), $from, $to, $this->orderOptions($request)), 'Store orders.');
    }

    public function sources(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return ApiResponse::success($this->sources->sources($this->tenantId(), $this->projectId(), $from, $to, $this->sourceOptions($request)), 'Order sources.');
    }

    /** Every order in scope for the same filter and sort; the page and page size are ignored on purpose. */
    public function exportOrders(Request $request): StreamedResponse
    {
        $format = $this->format($request);
        [$from, $to] = $this->window($request);
        $data = $this->sources->orders($this->tenantId(), $this->projectId(), $from, $to, $this->orderOptions($request), all: true);
        $keys = ['reference', 'placed_at', 'status', 'original_total', 'original_currency', 'net', 'method', 'source', 'medium', 'campaign', 'content', 'term', 'coupon', 'landing_path'];
        $head = ['Order', 'Placed at', 'Status', 'Original total', 'Original currency', 'Net ('.($data['currency'] ?? '').')', 'Method', 'Source', 'Medium', 'UTM campaign', 'UTM content', 'UTM term', 'Coupon', 'Landing page'];

        return TabularDownload::stream('store-orders-'.$data['period']['from'].'_'.$data['period']['to'], $head, $this->lines($data['rows'], $keys), $format);
    }

    /** Every source in scope for the same group, filter and sort. */
    public function exportSources(Request $request): StreamedResponse
    {
        $format = $this->format($request);
        [$from, $to] = $this->window($request);
        $data = $this->sources->sources($this->tenantId(), $this->projectId(), $from, $to, $this->sourceOptions($request), all: true);
        $keys = ['dimension_1', 'dimension_2', 'orders', 'confirmed', 'net_revenue', 'ga4_sessions', 'ga4_add_to_carts', 'ga4_purchases', 'ga4_revenue', 'confirmed_per_session'];
        $head = ['Source', 'Detail', 'Store orders', 'Store confirmed', 'Store net ('.($data['currency'] ?? '').')', 'GA4 sessions', 'GA4 add to carts', 'GA4 purchases', 'GA4 revenue', 'Store confirmed per GA4 session', 'Methods'];
        $lines = array_map(static function (array $row) use ($keys): array {
            $line = array_map(static fn (string $k): mixed => $row[$k] ?? null, $keys);
            $line[] = implode('; ', array_map(static fn (string $m, int $n): string => "$m: $n", array_keys($row['methods']), $row['methods']));

            return $line;
        }, $data['rows']);

        return TabularDownload::stream('order-sources-'.$data['group'].'-'.$data['period']['from'].'_'.$data['period']['to'], $head, $lines, $format);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return list<list<mixed>>
     */
    private function lines(array $rows, array $keys): array
    {
        return array_map(static fn (array $row): array => array_map(static fn (string $k): mixed => $row[$k] ?? null, $keys), $rows);
    }

    private function format(Request $request): string
    {
        return (string) $request->validate(['format' => ['required', 'string', 'in:csv,xlsx']])['format'];
    }

    /** @return array<string, mixed> */
    private function orderOptions(Request $request): array
    {
        return $request->validate([
            'sort' => ['sometimes', 'string', 'in:'.implode(',', StoreOrderSources::ORDER_SORTS)],
            'dir' => ['sometimes', 'string', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'method' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', OrderSourceEvidence::METHODS)],
        ]);
    }

    /** @return array<string, mixed> */
    private function sourceOptions(Request $request): array
    {
        return $request->validate([
            'group' => ['sometimes', 'string', 'in:'.implode(',', StoreOrderSources::GROUPS)],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', StoreOrderSources::SOURCE_SORTS)],
            'dir' => ['sometimes', 'string', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'method' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', OrderSourceEvidence::METHODS)],
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(Request $request): array
    {
        $v = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']]);
        $to = isset($v['to']) ? Carbon::parse($v['to'])->endOfDay() : Carbon::now()->endOfDay();
        $from = isset($v['from']) ? Carbon::parse($v['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        // The same bound the funnel reads under: a year.
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return [$from, $to];
    }

    private function tenantId(): string
    {
        return (string) $this->tenant->tenantId();
    }

    private function projectId(): string
    {
        return (string) $this->project->projectId();
    }
}
