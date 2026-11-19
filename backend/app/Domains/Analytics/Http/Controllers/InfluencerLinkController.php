<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers;

use App\Domains\Analytics\Models\InfluencerProjectLink;
use App\Domains\Analytics\Services\InfluencerLinks;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ANALYTICS-DATA-001 §6 — the influencer links of ONE project: list them, mint one.
 *
 * The only write in the analytics surface, and it writes a row of the existing short-link library plus the row that
 * says whose it is. The UTM is fixed by the server (`utm_source=influencer`, `utm_medium=influencer`, a campaign
 * unique in the project); the request cannot choose it, so it cannot collide with another influencer's.
 */
final class InfluencerLinkController extends Controller
{
    public function __construct(private readonly InfluencerLinks $links) {}

    public function index(): JsonResponse
    {
        [$tenantId, $projectId] = $this->scope();
        $rows = InfluencerProjectLink::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->orderBy('created_at')->get();
        $shorts = ShortLink::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->whereIn('id', $rows->pluck('short_link_id')->all())->get()->keyBy(fn (ShortLink $s): string => (string) $s->getKey());

        return ApiResponse::success($rows->map(fn (InfluencerProjectLink $l): array => $this->shape($l, $shorts->get((string) $l->short_link_id)))->all(), 'Influencer links.');
    }

    public function store(Request $request): JsonResponse
    {
        [$tenantId, $projectId] = $this->scope();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'destination' => ['required', 'string', 'max:2048'],
            'influencer_id' => ['nullable', 'uuid'],
            'collaboration_id' => ['nullable', 'uuid'],
            'coupon_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $link = $this->links->mint($tenantId, $projectId, $data, $request->user()?->id);
        $short = ShortLink::withoutGlobalScopes()->whereKey($link->short_link_id)->first();

        return ApiResponse::success($this->shape($link, $short), 'Influencer link created.', status: 201);
    }

    /** @return array<string, mixed> */
    private function shape(InfluencerProjectLink $l, ?ShortLink $short): array
    {
        return [
            'id' => (string) $l->getKey(),
            'name' => $l->name,
            'influencer_id' => $l->influencer_id,
            'collaboration_id' => $l->collaboration_id,
            'utm' => ['source' => $l->utm_source, 'medium' => $l->utm_medium, 'campaign' => $l->utm_campaign],
            'coupon_code' => $l->coupon_code,
            'is_active' => $l->is_active,
            'short_link_id' => (string) $l->short_link_id,
            'url' => $short === null ? null : $this->links->shareUrl($short),
            'clicks' => $short === null ? null : ['value' => (int) $short->clicks, 'source' => 'short_link_counter'],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function scope(): array
    {
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();
        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        return [$tenantId, $projectId];
    }
}
