<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Measurement\Ga4AttributionMatch;
use App\Domains\Integrations\Measurement\Ga4Classification;
use App\Domains\Integrations\Measurement\Ga4SiteAnalytics;
use App\Domains\Integrations\Measurement\Ga4StoreAnalytics;
use App\Domains\Integrations\Measurement\Ga4TrafficTable;
use App\Domains\Metrics\Services\AttributionTransparency;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GA4-ANALYTICS-PRODUCT-001 — the project's site analytics, from its selected GA4 property. */
final class SiteAnalyticsController extends Controller
{
    private const MAX_DAYS = 365;

    public function show(Request $request, Ga4SiteAnalytics $site): JsonResponse
    {
        [$from, $to] = $this->window($request);
        [$tenantId, $projectId] = $this->scope();

        return ApiResponse::success($site->build($tenantId, $projectId, $from, $to), 'Site analytics.');
    }

    /**
     * GA4-STORE-ANALYTICS-001 — the store as its GA4 property measured it, beside what the project's ad platforms
     * reported. The platform side is read under the same project scope and never added to the GA4 side.
     */
    public function store(Request $request, Ga4StoreAnalytics $store, AttributionTransparency $transparency): JsonResponse
    {
        [$from, $to] = $this->window($request);
        [$tenantId, $projectId] = $this->scope();

        return ApiResponse::success(
            $store->build($tenantId, $projectId, $from, $to, $transparency->reportedByPlatform($projectId, $from, $to)),
            'Store analytics.',
        );
    }

    /** GA4-PLATFORM-COMPARE-001 — platform-reported, store-confirmed and GA4-measured, each in its own cell. */
    public function compare(Request $request, AttributionTransparency $transparency): JsonResponse
    {
        [$from, $to] = $this->window($request);
        [$tenantId, $projectId] = $this->scope();

        return ApiResponse::success($transparency->threeSources($tenantId, $projectId, $from, $to), 'Three sources.');
    }

    /** TABLE-SERVER-PAGING-001 — one page of one breakdown; paging, sort and filter run in the query. */
    public function table(Request $request, Ga4TrafficTable $table): JsonResponse
    {
        [$from, $to] = $this->window($request);
        [$tenantId, $projectId] = $this->scope();

        return ApiResponse::success($table->page($tenantId, $projectId, $from, $to, $this->options($request)), 'Traffic table.');
    }

    /**
     * GA4-ATTRIBUTION-MATCH-001 — every ad-platform campaign against the GA4 traffic it produced.
     * Channel / campaign-level, from GA4's own session attribution; never an order-level match.
     */
    public function attribution(Request $request, Ga4AttributionMatch $match): JsonResponse
    {
        [$from, $to] = $this->window($request);
        [$tenantId, $projectId] = $this->scope();
        $v = $request->validate([
            'platform' => ['sometimes', 'nullable', 'string', 'max:40'],
            'channel' => ['sometimes', 'nullable', 'string', 'max:100'],
            'campaign' => ['sometimes', 'nullable', 'string', 'max:512'],
            'compare' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success($match->build($tenantId, $projectId, $from, $to, $v + ['internal' => true]), 'Attribution match.');
    }

    /** GA4-CLASSIFICATION-001 — the traffic grouped by one chosen axis, one fixed-size page at a time. */
    public function classification(Request $request, Ga4Classification $classification): JsonResponse
    {
        [$from, $to] = $this->window($request);
        [$tenantId, $projectId] = $this->scope();
        $v = $request->validate([
            'group' => ['required', 'string', 'in:'.implode(',', Ga4Classification::GROUPS)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer'],
            'sort' => ['sometimes', 'string', 'max:40'],
            'dir' => ['sometimes', 'string', 'in:asc,desc'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:40'],
            'channel' => ['sometimes', 'nullable', 'string', 'max:100'],
            'campaign' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        return ApiResponse::success($classification->page($tenantId, $projectId, $from, $to, $v), 'Classification.');
    }

    /** Every row in scope for the same breakdown, filter and sort — the page and page size are ignored on purpose. */
    public function export(Request $request, Ga4TrafficTable $table): StreamedResponse
    {
        [$from, $to] = $this->window($request);
        [$tenantId, $projectId] = $this->scope();
        $data = $table->page($tenantId, $projectId, $from, $to, $this->options($request), all: true);
        $head = ['Dimension 1', 'Dimension 2', 'Sessions', 'Engaged sessions', 'Key events', 'Add to carts', 'Checkouts', 'Purchases', 'Revenue', 'Engagement rate', 'Conversion rate'];
        $keys = ['dimension_1', 'dimension_2', 'sessions', 'engaged_sessions', 'key_events', 'add_to_carts', 'checkouts', 'purchases', 'revenue', 'engagement_rate', 'conversion_rate'];
        $lines = array_map(static fn (array $r): array => array_map(static function ($k) use ($r) {
            $v = $r[$k] ?? null;

            // A cell that starts like a formula is quoted as text: a UTM value is attacker-controlled.
            return is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) ? "'".$v : $v;
        }, $keys), $data['rows']);
        $name = 'traffic-'.$data['breakdown'].'-'.$data['period']['from'].'_'.$data['period']['to'];

        if ($request->query('format') === 'xlsx') {
            return response()->streamDownload(function () use ($head, $lines): void {
                $book = new Spreadsheet;
                $book->getActiveSheet()->fromArray([$head, ...$lines], null, 'A1');
                (new Xlsx($book))->save('php://output');
            }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($head, $lines): void {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, $head, ',', '"', '');
            foreach ($lines as $l) {
                fputcsv($fh, $l, ',', '"', '');
            }
            fclose($fh);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function options(Request $request): array
    {
        $v = $request->validate([
            'breakdown' => ['required', 'string', 'in:'.implode(',', Ga4TrafficTable::BREAKDOWNS)],
            'sort' => ['sometimes', 'string', 'in:dimension_1,dimension_2,sessions,engaged_sessions,key_events,add_to_carts,checkouts,purchases,revenue,engagement_rate,conversion_rate'],
            'dir' => ['sometimes', 'string', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'where_d1' => ['sometimes', 'nullable', 'string', 'max:512'],
            'where_d2' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        return $v;
    }

    /** @return array{0: string, 1: string} */
    private function scope(): array
    {
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();
        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        return [$tenantId, $projectId];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString())->startOfDay() : Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString())->startOfDay() : $to->copy()->subDays(29);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        return [$from, $to];
    }
}
