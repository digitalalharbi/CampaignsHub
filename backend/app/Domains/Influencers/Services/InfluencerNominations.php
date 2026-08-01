<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerNomination;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Putting a creator forward, and answering (INFL-003).
 *
 * The decision is the artefact. A collaboration records what was agreed; this records what was
 * ASKED and what came back, including the answers that were no — which is the half that was missing
 * and the half a brand asks about six months later.
 */
final class InfluencerNominations
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Propose a creator.
     *
     * Refuses a second live nomination of the same creator for the same campaign: two people
     * shortlisting the same person is a duplicate to collapse, not two opinions to store — and the
     * second would be decided separately, leaving the trail saying yes and no at once.
     */
    public function propose(Influencer $influencer, array $data, ?int $userId): InfluencerNomination
    {
        $existing = InfluencerNomination::query()
            ->where('influencer_id', $influencer->getKey())
            ->where('campaign_id', $data['campaign_id'] ?? null)
            ->where('status', 'proposed')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $nomination = InfluencerNomination::create([
            'influencer_id' => $influencer->getKey(),
            'campaign_id' => $data['campaign_id'] ?? null,
            'client_workspace_id' => $data['client_workspace_id'] ?? null,
            'status' => 'proposed',
            'proposed_fee' => $data['proposed_fee'] ?? null,
            'currency' => $data['currency'] ?? null,
            'rationale' => $data['rationale'] ?? null,
            'proposed_by' => $userId,
            'proposed_at' => Carbon::now(),
        ]);

        $this->audit->log(
            action: 'influencer.nomination.proposed',
            entityType: InfluencerNomination::class,
            entityId: (string) $nomination->getKey(),
            after: ['influencer' => $influencer->name, 'campaign_id' => $nomination->campaign_id],
            userId: $userId,
        );

        return $nomination->refresh();
    }

    /**
     * Answer it.
     *
     * A rejection REQUIRES a note. «No» with no reason is the answer that gets the same creator
     * proposed again next quarter, which is the waste this whole record exists to stop.
     */
    public function decide(InfluencerNomination $nomination, string $decision, ?string $note, ?int $userId): InfluencerNomination
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new RuntimeException('A nomination is approved or rejected.');
        }

        if ($nomination->status !== 'proposed') {
            throw new RuntimeException('This nomination has already been answered.');
        }

        if ($decision === 'rejected' && trim((string) $note) === '') {
            throw new RuntimeException('A rejection needs a reason.');
        }

        $nomination->forceFill([
            'status' => $decision,
            'decided_by' => $userId,
            'decided_at' => Carbon::now(),
            'decision_note' => $note,
        ])->save();

        $this->audit->log(
            action: 'influencer.nomination.'.$decision,
            entityType: InfluencerNomination::class,
            entityId: (string) $nomination->getKey(),
            reason: $note,
            userId: $userId,
        );

        return $nomination->refresh();
    }

    /** Withdrawn by whoever proposed it — before an answer, not instead of one. */
    public function withdraw(InfluencerNomination $nomination, ?int $userId): InfluencerNomination
    {
        if ($nomination->status !== 'proposed') {
            throw new RuntimeException('Only a nomination still awaiting an answer can be withdrawn.');
        }

        $nomination->forceFill(['status' => 'withdrawn', 'decided_at' => Carbon::now(), 'decided_by' => $userId])->save();

        $this->audit->log(
            action: 'influencer.nomination.withdrawn',
            entityType: InfluencerNomination::class,
            entityId: (string) $nomination->getKey(),
            userId: $userId,
        );

        return $nomination->refresh();
    }

    /**
     * Turn an approved nomination into real work.
     *
     * The link between the two is kept on the nomination, so the trail runs idea → decision →
     * contract without either end having to be inferred from names and dates. Idempotent: a
     * nomination that already became work hands back what it became rather than a second contract.
     */
    public function convert(InfluencerNomination $nomination, array $data, ?int $userId): InfluencerCollaboration
    {
        if ($nomination->collaboration_id !== null) {
            return InfluencerCollaboration::query()->findOrFail($nomination->collaboration_id);
        }

        if ($nomination->status !== 'approved') {
            throw new RuntimeException('Only an approved nomination becomes a collaboration.');
        }

        return DB::transaction(function () use ($nomination, $data, $userId): InfluencerCollaboration {
            $collaboration = InfluencerCollaboration::create([
                'influencer_id' => $nomination->influencer_id,
                'client_workspace_id' => $nomination->client_workspace_id,
                'campaign_id' => $nomination->campaign_id,
                'title' => $data['title'],
                'status' => 'draft',
                'currency' => $data['currency'] ?? $nomination->currency,
                'agreed_fee' => $data['agreed_fee'] ?? $nomination->proposed_fee,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'brief' => $data['brief'] ?? $nomination->rationale,
            ]);

            $nomination->forceFill(['collaboration_id' => $collaboration->getKey()])->save();

            $this->audit->log(
                action: 'influencer.nomination.converted',
                entityType: InfluencerNomination::class,
                entityId: (string) $nomination->getKey(),
                after: ['collaboration_id' => (string) $collaboration->getKey()],
                userId: $userId,
            );

            return $collaboration->refresh();
        });
    }
}
