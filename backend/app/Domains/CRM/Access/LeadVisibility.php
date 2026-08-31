<?php

declare(strict_types=1);

namespace App\Domains\CRM\Access;

use App\Domains\CRM\Models\Lead;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * LEAD-OPERATIONS-001 · TEAM-PROJECT-RBAC-001 — a lead's identity is a permission, not a column.
 *
 * ## What was being returned
 *
 * `LeadResource` sent `name`, `email`, `phone` and `notes` to anybody holding the tenant's
 * `leads.view` — which is everybody who can open the leads screen, including the media buyer whose
 * job is the cost per lead and the analyst building a dashboard. A client's customers were readable
 * by the whole agency because reading the COUNT and reading the PEOPLE were one permission.
 *
 * ## Redaction has to be honest, not blank
 *
 * A withheld name is not an empty name. Returning `''` says «this person gave no name», which is a
 * false statement about the client's lead and would be read as a data-quality problem — somebody
 * would go looking for a bug in the intake. The field says it is withheld, so the reader knows the
 * information exists and that they are not entitled to it.
 *
 * ## And the search box is part of the redaction
 *
 * A reader who cannot see a phone number but CAN search by one has the number: they type it and
 * watch the count change. A redaction with an oracle beside it is not a redaction, which is why
 * `searchable()` exists and why the controller asks it rather than deciding for itself.
 */
final class LeadVisibility
{
    public function __construct(private readonly ProjectAbilities $abilities) {}

    /**
     * May this reader see who this lead IS?
     *
     * The lead's own project decides. A lead with no project — an older row, a manually created one —
     * falls back to the tenant permission, which is the layer that owned this question before there
     * was a project layer; falling back to «no» would blank every historical lead for everybody.
     */
    public function maySeeIdentity(?User $user, Lead $lead): bool
    {
        if ($user === null) {
            return false;
        }

        $projectId = $lead->project_id === null ? null : (string) $lead->project_id;

        return $projectId === null
            ? $user->hasPermission('leads.pii.view')
            : $this->abilities->allows($user, $projectId, ProjectCapability::LEADS_PII_VIEW);
    }

    /**
     * May this reader search by name, email or phone in this project?
     *
     * Asked per SCOPE rather than per lead, because a search runs before there are any leads to ask
     * about. Without a project in hand — a tenant-wide list — the tenant permission answers: the
     * search would otherwise span projects the reader may hold identities in and projects they may
     * not, and a query that is half-allowed cannot be half-run.
     */
    public function searchable(?User $user, ?string $projectId): bool
    {
        if ($user === null) {
            return false;
        }

        return $projectId === null
            ? $user->hasPermission('leads.pii.view')
            : $this->abilities->allows($user, $projectId, ProjectCapability::LEADS_PII_VIEW);
    }

    /**
     * Narrow a listing to what this reader is entitled to see AT ALL.
     *
     * ## Who is narrowed, and why it is not everybody
     *
     * A media buyer and a management viewer see every row and no identities — the volume is the
     * point of their screen, and there is nothing on it to browse. Narrowing them would break the
     * lead count for the people who need it while protecting nothing.
     *
     * The reader this exists for is the one who CAN see identities but does not run the pipeline:
     * the lead agent. They are given leads one at a time, and a list of every customer's name and
     * phone number is the one thing this permission model is for. So the narrowing follows the two
     * capabilities together — identity without assignment is an agent, and an agent sees what they
     * were given.
     *
     * `leads.assign` is what distinguishes somebody who runs the pipeline from somebody who works in
     * it: a supervisor who can hand a lead to a colleague can obviously read the colleague's leads.
     * Unowned leads stay with the supervisor, because a NEW lead nobody has been given yet is
     * exactly the row an agent must not quietly claim.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeForReader(Builder $query, ?User $user, ?string $projectId): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        /*
         * With a project in hand the question is simple: identities without the pipeline is an agent.
         *
         * Without one, the tenant role cannot answer it. A tenant owner legitimately holds
         * `leads.pii.view` and has no reason to hold `leads.assign` — nobody assigns leads to them —
         * and narrowing them to their own leads would empty the inbox for the person who owns it.
         * «Agent» is a fact about a project MEMBERSHIP, so an unscoped list asks whether this reader
         * is an agent anywhere, and leaves everybody else alone.
         */
        if ($projectId === null) {
            if (! $this->isAgentSomewhere($user)) {
                return $query;
            }

            return $query->where('owner_id', $user->id);
        }

        $identities = $this->abilities->allows($user, $projectId, ProjectCapability::LEADS_PII_VIEW);
        $supervises = $this->abilities->allows($user, $projectId, ProjectCapability::LEADS_ASSIGN);

        if (! $identities || $supervises) {
            return $query;
        }

        return $query->where('owner_id', $user->id);
    }

    /**
     * Is this reader an AGENT on any project — holding identities there without running the pipeline?
     *
     * One query over their own memberships, asked only when no project was named. It is the cheap
     * half of a question that would otherwise need a lookup per lead; the expensive half, per-row
     * identity, is memoised by `ProjectAbilities` for exactly the same reason.
     */
    private function isAgentSomewhere(User $user): bool
    {
        $projectIds = ProjectMembership::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('project_id');

        foreach ($projectIds as $projectId) {
            $id = (string) $projectId;

            if (
                $this->abilities->allows($user, $id, ProjectCapability::LEADS_PII_VIEW)
                && ! $this->abilities->allows($user, $id, ProjectCapability::LEADS_ASSIGN)
            ) {
                return true;
            }
        }

        return false;
    }

    /** The sentence a withheld field carries, so the reader knows it exists and is not empty. */
    public const WITHHELD = 'withheld';
}
