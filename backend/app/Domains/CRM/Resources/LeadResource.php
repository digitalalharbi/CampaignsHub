<?php

declare(strict_types=1);

namespace App\Domains\CRM\Resources;

use App\Domains\CRM\Access\LeadVisibility;
use App\Domains\CRM\Attribution\LeadAttributionChain;
use App\Domains\CRM\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
final class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /*
         * LEAD-OPERATIONS-001 — who this person IS, only for a reader entitled to it.
         *
         * These four fields went to anybody holding the tenant's `leads.view`, which is everybody
         * who can open the screen — the media buyer whose job is the cost per lead, the analyst
         * building a dashboard. Reading the COUNT and reading the PEOPLE were one permission.
         *
         * `identity_withheld` travels beside them so the UI can say «you are not permitted to see
         * this» rather than drawing a row that looks like a lead who gave no details. A blank name
         * is a false statement about the client's lead, and somebody would go looking for the bug.
         */
        $identity = app(LeadVisibility::class)->maySeeIdentity($request->user(), $this->resource);

        return [
            'id' => $this->id,
            'identity_withheld' => ! $identity,
            'name' => $identity ? $this->name : null,
            'email' => $identity ? $this->email : null,
            'phone' => $identity ? $this->phone : null,
            'source' => $this->source,
            'status' => $this->status,
            'estimated_value' => (float) $this->estimated_value,
            'currency' => $this->currency,
            // A note is what an agent wrote about the conversation, so it is identity too.
            'notes' => $identity ? $this->notes : null,
            'tags' => $this->tags ?? [],
            'company_id' => $this->company_id,
            'contact_id' => $this->contact_id,
            'owner_id' => $this->owner_id,
            'is_converted' => $this->isConverted(),
            'converted_opportunity_id' => $this->converted_opportunity_id,
            'converted_at' => optional($this->converted_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            /*
             * LEAD-DEDUP-001 — the duplicate RELATIONSHIP, on the row.
             *
             * `canonical_lead_id` and `duplicate_reason` have been written since the dedup work
             * shipped and no surface has ever read them, so every repeat submission looked like a
             * separate person to anybody using the product. The whole design is «recorded twice,
             * counted once» — and «counted once» is a statement a reader has to be able to SEE, or
             * it is only a statement about the database.
             *
             * `duplicate_reason` travels with the link rather than being inferred from it, because
             * `ambiguous` is not a kind of duplicate: it is a lead whose email says one person and
             * whose phone says another, deliberately linked to NEITHER. Collapsing the two would
             * present a refusal to guess as a resolved match.
             */
            'canonical_lead_id' => $this->canonical_lead_id,
            'duplicate_reason' => $this->duplicate_reason,
            /*
             * How many later arrivals this lead absorbed. Present only when it was counted — a
             * `withCount` on the query, never a relation touched per row, because this list is
             * paginated and the alternative is a query per lead.
             */
            'duplicate_count' => $this->whenCounted('duplicates'),
            /*
             * LEAD-SOURCE-ATTRIBUTION-001 — the chain, and what it cannot say.
             *
             * Sent on every lead rather than only on the detail view, because a list that cannot show
             * which campaign produced a row is the list a lead-generation client spends their day in.
             * It costs no query: the chain is computed from columns this row already carries, and it
             * is forbidden from opening a metrics table — a click is not a person.
             *
             * Not gated behind identity permission. The chain describes the AD that ran, which is
             * the client's own media buying and not the person's data; an agent who may see that a
             * lead exists may see which campaign paid for it.
             */
            'attribution' => app(LeadAttributionChain::class)->for($this->resource),
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),
        ];
    }
}
