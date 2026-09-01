<?php

declare(strict_types=1);

namespace App\Domains\CRM\Actions;

use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * LEAD-OPERATIONS-001 — moving a lead, and the timestamps that come with the move.
 *
 * ## Why the stamps live here and not in the caller
 *
 * `assigned_at`, `first_attempt_at`, `first_contact_at` and `qualified_at` have existed since the
 * provenance work and **nothing has ever written one**. They were left to whichever caller happened
 * to move the lead — which is the same shape as every other defect in this product where a column
 * exists and no code fills it, and it fails the same way: the first report anybody runs is empty and
 * looks like a data problem rather than a missing write.
 *
 * A stage change and its timestamp are one fact. Recording them apart means they can disagree, and
 * a lead that says `contacted` with no `first_contact_at` is a row nobody can compute a response
 * time from.
 *
 * ## First is first
 *
 * `first_contact_at` is written once and never overwritten; `last_contact_at` is written every time.
 * The distinction is the whole point of having two columns — response time is measured from the
 * FIRST conversation, and a team that calls a lead again next month must not thereby improve their
 * recorded response time by a month.
 *
 * ## What it refuses
 *
 * A transition the pipeline does not allow. Not to be strict for its own sake: a `new` lead becoming
 * `won` without anybody having been assigned it or having spoken to the person is a sale nobody can
 * account for, and every conversion rate computed from that column afterwards is describing work
 * that has no record. The stages allow moving BACK one step, because a real pipeline goes backwards
 * and a mis-click has to be correctable.
 */
final class AdvanceLead
{
    /**
     * @param  Carbon|null  $at  the moment of the move; the caller's clock, so a backfill can pass its own
     */
    public function execute(Lead $lead, LeadStage $to, ?User $by = null, ?Carbon $at = null): Lead
    {
        $from = LeadStage::tryFrom((string) $lead->status) ?? LeadStage::New;
        $at ??= Carbon::now();

        if (! $from->allows($to)) {
            throw new InvalidArgumentException(
                "A lead at [{$from->value}] cannot move to [{$to->value}].",
            );
        }

        $lead->status = $to->value;

        /*
         * An attempt is work done and is not a conversation. Both `contact_attempted` and
         * `contacted` increment the counter, because reaching somebody on the third try means three
         * calls were made — counting only the failures would report a team that never succeeds as
         * the hardest working one.
         */
        if ($to === LeadStage::ContactAttempted || $to === LeadStage::Contacted) {
            $lead->contact_attempts = (int) ($lead->contact_attempts ?? 0) + 1;
            $lead->first_attempt_at ??= $at;
        }

        if ($to === LeadStage::Contacted) {
            $lead->first_contact_at ??= $at;
            $lead->last_contact_at = $at;
        }

        if ($to === LeadStage::Assigned) {
            $lead->assigned_at ??= $at;
        }

        if ($to === LeadStage::Qualified) {
            $lead->qualified_at ??= $at;
        }

        /*
         * A finished lead has nothing to follow up. Leaving the promise behind would keep it on the
         * overdue list forever — the single most reliable way to make a team stop trusting one.
         */
        if ($to->isTerminal()) {
            $lead->next_follow_up_at = null;
        }

        $lead->save();

        return $lead;
    }

    /**
     * Hand a lead to somebody, or take it back.
     *
     * Assignment is its own act rather than a stage change with an owner attached: reassigning a
     * lead that is already `contacted` must not send it back to `assigned` and lose the fact that
     * somebody has spoken to this person.
     */
    public function assign(Lead $lead, ?int $ownerId, ?Carbon $at = null): Lead
    {
        $at ??= Carbon::now();
        $lead->owner_id = $ownerId;

        if ($ownerId !== null) {
            $lead->assigned_at ??= $at;

            // A lead nobody had touched has now been given to somebody, which is a stage in itself.
            if ((string) $lead->status === LeadStage::New->value) {
                $lead->status = LeadStage::Assigned->value;
            }
        }

        $lead->save();

        return $lead;
    }

    /** Record what the agent promised. Null clears it — «no call-back planned» is an answer. */
    public function scheduleFollowUp(Lead $lead, ?Carbon $when): Lead
    {
        $lead->next_follow_up_at = $when;
        $lead->save();

        return $lead;
    }
}
