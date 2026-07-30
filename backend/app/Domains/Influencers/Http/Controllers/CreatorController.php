<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Http\Controllers;

use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Services\CreatorAccess;
use App\Domains\Influencers\Services\CreatorPresenter;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The creator's own side of the influencers portal (INFL-002, ADR 0002).
 *
 * Everything here is scoped by WHO IS ASKING, never by an id in the URL. There is no
 * `?influencer_id=` on any of these endpoints, because the moment such a parameter exists it has to
 * be authorised on every route, and one forgotten check is one creator reading another's fee.
 *
 * Distinct from CollaborationController in three ways that are the point of a separate surface:
 *
 *   1. No `influencers.*` permission is required or consulted. A creator is not a low-privilege
 *      operator — they are the other party to an agreement, and the agency's permission vocabulary
 *      does not describe them. What they may do follows from the agreement's state.
 *   2. The money is inverted (see CreatorPresenter): they see what they are paid, never what the
 *      client is billed.
 *   3. They submit; they never approve. Approval is the agency's act, and a creator who could set
 *      `approved` would be signing off their own work.
 */
final class CreatorController extends Controller
{
    public function __construct(
        private readonly CreatorAccess $access,
        private readonly CreatorPresenter $presenter,
    ) {}

    /**
     * GET /api/v1/influencers/me — who am I here, and what is waiting for me.
     *
     * 403 rather than an empty payload when the login has no roster entry: "you are not a creator in
     * this workspace" and "you are a creator with nothing to do" are different answers, and a blank
     * screen that means the first is the kind of thing people file as a bug for weeks.
     */
    public function me(Request $request): JsonResponse
    {
        $profile = $this->access->profile($request->user());
        abort_if($profile === null, 403, 'This account is not a creator in this workspace.');

        $collaborations = $this->access->collaborations($request->user())->with('deliverables')->get();

        return ApiResponse::success([
            'creator' => [
                'id' => (string) $profile->getKey(),
                'name' => $profile->name,
                'handle' => $profile->handle,
                'primary_platform' => $profile->primary_platform,
                'profile_url' => $profile->profile_url,
                // Audience figures are the creator's own facts, so they see them. `internal_notes`,
                // `tier` and `owner_id` are the agency's assessment OF them and are not returned.
                'followers' => $profile->followers,
                'engagement_rate' => $profile->engagement_rate === null ? null : (string) $profile->engagement_rate,
            ],
            'summary' => [
                'offers_awaiting_response' => $collaborations->whereNull('creator_decision')->count(),
                'active' => $collaborations->where('creator_decision', 'accepted')->count(),
                'deliverables_awaiting_me' => $collaborations
                    ->where('creator_decision', 'accepted')
                    ->flatMap->deliverables
                    ->whereIn('status', ['pending', 'rejected'])->count(),
            ],
        ], 'Creator profile.');
    }

    /** GET /api/v1/influencers/me/collaborations */
    public function collaborations(Request $request): JsonResponse
    {
        $items = $this->access->collaborations($request->user())
            ->with(['deliverables', 'client'])
            ->orderByDesc('terms_sent_at')
            ->get();

        return ApiResponse::success([
            'collaborations' => $items->map(fn (InfluencerCollaboration $c) => $this->presenter->collaboration($c))->all(),
        ], 'Your collaborations.');
    }

    /** GET /api/v1/influencers/me/collaborations/{collaboration} */
    public function show(Request $request, string $collaboration): JsonResponse
    {
        $model = $this->access->collaboration($request->user(), $collaboration);
        abort_if($model === null, 404);

        return ApiResponse::success(
            ['collaboration' => $this->presenter->collaboration($model->load(['deliverables', 'client']))],
            'Collaboration.',
        );
    }

    /**
     * POST /api/v1/influencers/me/collaborations/{collaboration}/respond — accept or decline terms.
     *
     * Answerable ONCE. A creator who could flip their answer after work began would leave the agency
     * unable to say whether the piece they are holding was ever agreed to; changing an accepted
     * agreement is a renegotiation, which is a conversation, not a toggle.
     */
    public function respond(Request $request, string $collaboration): JsonResponse
    {
        $model = $this->access->collaboration($request->user(), $collaboration);
        abort_if($model === null, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_if(
            $model->creator_decision !== null,
            422,
            'You have already answered these terms. Ask the agency to send revised terms.',
        );

        $model->forceFill([
            'creator_decision' => $data['decision'],
            'creator_responded_at' => now(),
            'creator_decline_reason' => $data['decision'] === 'declined' ? ($data['reason'] ?? null) : null,
            // The agreement's own status follows the answer, so the agency's list does not need a
            // second place to look for "did they say yes?".
            'status' => $data['decision'] === 'accepted' ? 'active' : 'declined',
        ])->save();

        return ApiResponse::success(
            ['collaboration' => $this->presenter->collaboration($model->fresh(['deliverables', 'client']))],
            $data['decision'] === 'accepted' ? 'Terms accepted.' : 'Terms declined.',
        );
    }

    /**
     * POST /api/v1/influencers/me/collaborations/{c}/deliverables/{d}/submit.
     *
     * The one write a creator makes to the work itself: a URL and, at most, a note. The status moves
     * to `submitted` and nowhere else — `approved` and `published` are the agency's to set, and the
     * enum here is the reason a creator cannot approve their own piece by posting a status.
     */
    public function submitDeliverable(Request $request, string $collaboration, string $deliverable): JsonResponse
    {
        $model = $this->access->collaboration($request->user(), $collaboration);
        abort_if($model === null, 404);

        abort_unless(
            $model->isAcceptedByCreator(),
            422,
            'Accept the terms before submitting work.',
        );

        $item = $this->access->deliverable($request->user(), $collaboration, $deliverable);
        abort_if($item === null, 404);

        // Re-submitting an approved or published piece would quietly withdraw an approval the agency
        // already gave — and, for a published post, one the client has already been shown.
        abort_unless(
            in_array($item->status, ['pending', 'rejected'], true),
            422,
            'This deliverable is no longer awaiting your submission.',
        );

        $data = $request->validate([
            'submitted_url' => ['required', 'url', 'max:2048'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $item->forceFill([
            'submitted_url' => $data['submitted_url'],
            'status' => 'submitted',
            'submitted_at' => now(),
            // A resubmission after rejection clears the old feedback: leaving it would show the
            // creator a complaint about work they have already replaced.
            'feedback' => null,
        ])->save();

        return ApiResponse::success(
            ['collaboration' => $this->presenter->collaboration($model->fresh(['deliverables', 'client']))],
            'Submitted for review.',
        );
    }
}
