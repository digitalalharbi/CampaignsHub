<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Services;

use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerDeliverable;

/**
 * What a collaboration looks like TO THE CREATOR (INFL-002).
 *
 * A separate presenter rather than a flag on the agency's, because the money rule is not narrower on
 * this side — it is INVERTED, and a boolean cannot express an inversion:
 *
 *   agency surface  → `agreed_fee` (billed to the client) always; `influencer_fee` and the margin
 *                     behind `influencers.view_costs`.
 *   creator surface → `influencer_fee` (what THEY are paid) always; `agreed_fee` and the margin
 *                     NEVER, at any permission level.
 *
 * Reusing the agency presenter with "hide costs" would have shown the creator `agreed_fee` — the
 * agency's markup on their own work, disclosed to the one person who must not be told it. The two
 * views hide opposite columns and that is why they are two functions.
 *
 * Also never crossed over: `internal_notes` (about them, not for them), the client's identity beyond
 * the display name they were already briefed on, and any other creator's anything.
 */
final class CreatorPresenter
{
    /** @return array<string, mixed> */
    public function collaboration(InfluencerCollaboration $c, bool $withDeliverables = true): array
    {
        $deliverables = $c->deliverables ?? collect();

        $payload = [
            'id' => (string) $c->getKey(),
            'title' => $c->title,
            'status' => $c->status,
            'currency' => $c->currency,
            // Their pay. Not the client's price — see the class docblock.
            'fee' => $c->influencer_fee === null ? null : (string) $c->influencer_fee,
            'starts_on' => $c->starts_on?->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
            'brief' => $c->brief,
            'client_name' => $c->client?->name,
            'offered_at' => $c->terms_sent_at?->toIso8601String(),
            'decision' => $c->creator_decision,
            'responded_at' => $c->creator_responded_at?->toIso8601String(),
            // What the creator may do right now, decided here rather than in the interface — a button
            // the backend would refuse is a lie told in advance.
            'can_respond' => $c->creator_decision === null,
            'can_submit' => $c->isAcceptedByCreator(),
        ];

        if ($withDeliverables) {
            $payload['deliverables'] = $deliverables
                ->map(fn (InfluencerDeliverable $d) => $this->deliverable($d, $c))->values()->all();
            $payload['progress'] = [
                'total' => $deliverables->count(),
                'awaiting_me' => $deliverables->whereIn('status', ['pending', 'rejected'])->count(),
                'with_agency' => $deliverables->where('status', 'submitted')->count(),
                'done' => $deliverables->whereIn('status', ['approved', 'published'])->count(),
            ];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function deliverable(InfluencerDeliverable $d, InfluencerCollaboration $c): array
    {
        return [
            'id' => (string) $d->getKey(),
            'type' => $d->type,
            'platform' => $d->platform,
            'status' => $d->status,
            'due_on' => $d->due_on?->toDateString(),
            'submitted_url' => $d->submitted_url,
            'submitted_at' => $d->submitted_at?->toIso8601String(),
            'published_at' => $d->published_at?->toIso8601String(),
            'is_overdue' => $d->isOverdue(),
            // Feedback is written to be read by them — that is what makes a rejection actionable
            // rather than just a refusal. `internal_notes` is the field that is never shared.
            'feedback' => $d->feedback,
            // Submitting is only possible once the agreement is accepted, and only while the piece is
            // still theirs to act on. An approved piece is not re-openable by re-submitting a URL.
            'can_submit' => $c->isAcceptedByCreator() && in_array($d->status, ['pending', 'rejected'], true),
        ];
    }
}
