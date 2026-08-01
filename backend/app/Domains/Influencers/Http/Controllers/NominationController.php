<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Http\Controllers;

use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerNomination;
use App\Domains\Influencers\Services\InfluencerNominations;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Putting creators forward for a campaign, and answering (INFL-003).
 *
 * Two permissions, deliberately different: `influencers.manage` proposes, and DECIDING needs
 * `influencers.approve`. Anyone who can add a creator to the roster can suggest one; committing the
 * agency to them is somebody else's call, and collapsing the two would make the shortlist a rubber
 * stamp its own author holds.
 */
final class NominationController extends Controller
{
    public function __construct(private readonly InfluencerNominations $nominations) {}

    /** GET /influencers/nominations */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.view'), 403);

        $query = InfluencerNomination::query()
            ->with(['influencer:id,name,handle,primary_platform,followers,tier'])
            ->orderByDesc('created_at');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($campaign = $request->string('campaign_id')->toString()) {
            $query->where('campaign_id', $campaign);
        }

        return ApiResponse::success(
            $query->get()->map(fn (InfluencerNomination $n) => $this->shape($n))->all(),
            'Nominations.',
        );
    }

    /** POST /influencers/nominations */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.manage'), 403);

        $data = $request->validate([
            'influencer_id' => ['required', 'uuid', Rule::exists('influencers', 'id')],
            'campaign_id' => ['nullable', 'uuid'],
            'client_workspace_id' => ['nullable', 'uuid'],
            'proposed_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'rationale' => ['nullable', 'string', 'max:2000'],
        ]);

        // Resolved through the tenant-scoped model, so an id belonging to another tenant is a 404
        // rather than a nomination of somebody else's creator.
        $influencer = Influencer::query()->findOrFail($data['influencer_id']);

        $nomination = $this->nominations->propose($influencer, $data, $request->user()?->id);

        return ApiResponse::success($this->shape($nomination), 'Nomination recorded.', status: 201);
    }

    /** POST /influencers/nominations/{nomination}/decide */
    public function decide(Request $request, InfluencerNomination $nomination): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.approve'), 403);

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $decided = $this->nominations->decide($nomination, $data['decision'], $data['note'] ?? null, $request->user()?->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success($this->shape($decided), 'Nomination answered.');
    }

    /** DELETE /influencers/nominations/{nomination} — withdraw, before an answer rather than instead of one. */
    public function withdraw(Request $request, InfluencerNomination $nomination): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.manage'), 403);

        try {
            $withdrawn = $this->nominations->withdraw($nomination, $request->user()?->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success($this->shape($withdrawn), 'Nomination withdrawn.');
    }

    /** POST /influencers/nominations/{nomination}/collaboration — turn a yes into real work. */
    public function convert(Request $request, InfluencerNomination $nomination): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('influencers.manage'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'agreed_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'brief' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $collaboration = $this->nominations->convert($nomination, $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success([
            'collaboration_id' => (string) $collaboration->getKey(),
            'nomination' => $this->shape($nomination->refresh()),
        ], 'The nomination became a collaboration.', status: 201);
    }

    /** @return array<string, mixed> */
    private function shape(InfluencerNomination $n): array
    {
        return [
            'id' => (string) $n->getKey(),
            'status' => $n->status,
            'campaign_id' => $n->campaign_id,
            'client_workspace_id' => $n->client_workspace_id,
            'proposed_fee' => $n->proposed_fee === null ? null : (string) $n->proposed_fee,
            'currency' => $n->currency,
            'rationale' => $n->rationale,
            'proposed_at' => $n->proposed_at?->toIso8601String(),
            'decided_at' => $n->decided_at?->toIso8601String(),
            'decision_note' => $n->decision_note,
            // Named so the interface can offer «create the collaboration» only where it is real.
            'is_convertible' => $n->isConvertible(),
            'collaboration_id' => $n->collaboration_id,
            'influencer' => $n->influencer === null ? null : [
                'id' => (string) $n->influencer->getKey(),
                'name' => $n->influencer->name,
                'handle' => $n->influencer->handle,
                'primary_platform' => $n->influencer->primary_platform,
                'followers' => $n->influencer->followers,
                'tier' => $n->influencer->tier,
            ],
        ];
    }
}
