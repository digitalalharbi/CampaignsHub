<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Http\Controllers;

use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerDeliverable;
use App\Domains\Influencers\Models\InfluencerDeliverableResult;
use App\Domains\Influencers\Models\InfluencerTrackingAsset;
use App\Domains\Influencers\Services\InfluencerAttribution;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Tracking links, discount codes and per-post results (INFL-003).
 *
 * Every figure this returns says where it came from. A click on a link is measured by this platform,
 * because this platform serves the redirect; a redemption of a discount code happened in the brand's
 * own store, which this platform has never seen. Presenting the two as one number would be the
 * clearest possible version of the thing the contract forbids.
 */
final class AttributionController extends Controller
{
    public function __construct(private readonly InfluencerAttribution $attribution) {}

    /** GET /influencers/collaborations/{collaboration}/tracking */
    public function index(Request $request, InfluencerCollaboration $collaboration): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.view'), 403);

        $assets = InfluencerTrackingAsset::query()
            ->where('collaboration_id', $collaboration->getKey())
            ->orderBy('created_at')
            ->get();

        return ApiResponse::success(
            $assets->map(fn (InfluencerTrackingAsset $a) => $this->shapeAsset($a))->all(),
            'Tracking assets.',
        );
    }

    /** POST /influencers/collaborations/{collaboration}/tracking */
    public function store(Request $request, InfluencerCollaboration $collaboration): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.manage'), 403);

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:link,discount_code'],
            'deliverable_id' => ['nullable', 'uuid'],
            'destination_url' => ['nullable', 'url', 'max:2048'],
            'code' => ['nullable', 'string', 'max:64'],
            'discount_type' => ['nullable', 'string', 'in:percent,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $asset = $this->attribution->issue($collaboration, $data['kind'], $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success($this->shapeAsset($asset), 'Tracking asset issued.', status: 201);
    }

    /** PATCH /influencers/tracking/{asset}/redemptions — what the store reported. */
    public function recordRedemptions(Request $request, InfluencerTrackingAsset $asset): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.manage'), 403);

        $data = $request->validate(['redemptions' => ['required', 'integer', 'min:0']]);

        try {
            $updated = $this->attribution->recordRedemptions($asset, $data['redemptions'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success($this->shapeAsset($updated), 'Redemptions recorded.');
    }

    /** POST /influencers/deliverables/{deliverable}/results */
    public function recordResult(Request $request, InfluencerDeliverable $deliverable): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.manage'), 403);

        $data = $request->validate([
            'impressions' => ['nullable', 'integer', 'min:0'],
            'reach' => ['nullable', 'integer', 'min:0'],
            'engagements' => ['nullable', 'integer', 'min:0'],
            'clicks' => ['nullable', 'integer', 'min:0'],
            'conversions' => ['nullable', 'integer', 'min:0'],
            'revenue' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'measured_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        /*
         * Always `manual`, and never taken from the request.
         *
         * `platform` is what a real connector will write when one exists. Letting a caller name the
         * source would let a hand-typed number be labelled as measured, which is the single claim
         * this whole surface is built to keep honest.
         */
        $result = $this->attribution->recordResult($deliverable, $data, 'manual', $request->user()?->id);

        return ApiResponse::success($this->shapeResult($result), 'Result recorded.', status: 201);
    }

    /** GET /influencers/deliverables/{deliverable}/results */
    public function results(Request $request, InfluencerDeliverable $deliverable): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.view'), 403);

        $results = InfluencerDeliverableResult::query()
            ->where('deliverable_id', $deliverable->getKey())
            ->orderBy('source')
            ->get();

        return ApiResponse::success(
            $results->map(fn (InfluencerDeliverableResult $r) => $this->shapeResult($r))->all(),
            'Deliverable results.',
        );
    }

    /**
     * GET /t/{code} — the public redirect. No auth, no tenant, no session.
     *
     * This is what makes a click count real: the platform serves the hop itself rather than trusting
     * a number typed in later. An unknown or retired code goes to the marketing site instead of
     * erroring — the person holding the link is a stranger who did nothing wrong, and a 404 tells
     * them only that the brand looks broken.
     */
    public function redirect(string $code): RedirectResponse
    {
        $asset = $this->attribution->resolveAndCount($code);

        $fallback = Frontend::origin().'/';

        return redirect()->away($asset?->destination_url ?: $fallback, 302);
    }

    /** @return array<string, mixed> */
    private function shapeAsset(InfluencerTrackingAsset $a): array
    {
        return [
            'id' => (string) $a->getKey(),
            'kind' => $a->kind,
            'code' => $a->code,
            'deliverable_id' => $a->deliverable_id,
            'destination_url' => $a->destination_url,
            'share_url' => $a->kind === 'link'
                ? rtrim((string) config('app.url'), '/').'/t/'.$a->code
                : null,
            'discount_type' => $a->discount_type,
            'discount_value' => $a->discount_value === null ? null : (string) $a->discount_value,
            'clicks' => $a->clicks,
            'last_clicked_at' => $a->last_clicked_at?->toIso8601String(),
            'redemptions' => $a->redemptions,
            'redemptions_source' => $a->redemptions_source,
            /*
             * The honesty flag the interface renders against.
             *
             * False means the zero beside this code is an ABSENCE of information rather than a
             * result — nobody has connected the store and nobody has typed a figure.
             */
            'count_is_measured' => $a->countIsMeasured(),
            'is_active' => $a->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private function shapeResult(InfluencerDeliverableResult $r): array
    {
        return [
            'id' => (string) $r->getKey(),
            'source' => $r->source,
            'impressions' => $r->impressions,
            'reach' => $r->reach,
            'engagements' => $r->engagements,
            'clicks' => $r->clicks,
            'conversions' => $r->conversions,
            'revenue' => $r->revenue === null ? null : (string) $r->revenue,
            'currency' => $r->currency,
            // Null when either side is unknown — never a 0% that reads as "nobody engaged".
            'engagement_rate' => $r->engagementRate(),
            'measured_at' => $r->measured_at?->toIso8601String(),
            'note' => $r->note,
        ];
    }
}
