<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Http\Controllers;

use App\Domains\Analytics\Support\AnalyticsReadCache;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\ContentIntake\Services\ContentStanding;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CONTENT-CARDS-001 — the rank, the class and the objective result of every piece of content in the library's scope.
 *
 * Same scope as the library and its overview: reach-bound, the same filters, the same activity window. Bounded to the
 * {@see self::MAX_ROWS} biggest spenders (the overview's bound), and it says so — `read` against `in_scope` — instead of
 * ranking a hidden remainder. Per-tenant cache, keyed by the reader too, because reach is a property of the reader.
 */
final class ContentStandingController extends Controller
{
    private const MAX_ROWS = 200;

    private const FILTERS = [
        'provider', 'providers', 'format', 'formats', 'status', 'statuses', 'campaign_ids', 'ad_set_ids', 'ad_ids',
        'project_ids', 'client_ids', 'search', 'kinds', 'objectives', 'paths',
    ];

    public function __construct(private readonly CreativeRows $rows, private readonly ContentStanding $standing) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('campaigns.view'), 403);
        [$from, $to] = $this->rows->window(
            $request->filled('from') ? $request->string('from')->toString() : null,
            $request->filled('to') ? $request->string('to')->toString() : null,
        );
        $filters = $request->only(self::FILTERS);
        ksort($filters);

        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projects = array_values(array_filter((array) ($filters['project_ids'] ?? [])));
        // The cache epoch is per project; a read across several is filed under the reader, which is what bounds its reach.
        $scope = count($projects) === 1 ? (string) $projects[0] : 'reach-'.$user->getKey();
        $variant = md5(json_encode([$user->getKey(), $filters, $from->toDateString(), $to->toDateString()], JSON_THROW_ON_ERROR));

        $payload = AnalyticsReadCache::remember('content-standing', $tenantId, $scope, $variant, function () use ($user, $filters, $from, $to): array {
            $query = BoundAccountVisibility::apply(ExternalCreative::query(), 'external_creatives');
            $this->rows->applyReach($query, $user);
            $this->rows->applyFilters($query, $filters);
            $this->rows->applyPeriodActivity($query, $from, $to);
            $total = (clone $query)->count();
            $creatives = $this->rows->applySort($query, 'spend', $from, $to)->limit(self::MAX_ROWS)->get();
            $present = $this->rows->present($creatives, $from, $to, true, false);

            return $this->standing->for($present) + [
                'read' => count($present),
                'in_scope' => $total,
                'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            ];
        });

        return ApiResponse::success($payload, 'Content standing.');
    }
}
