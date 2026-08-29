<?php

declare(strict_types=1);

namespace App\Domains\CRM\Resources;

use App\Domains\CRM\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
final class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source,
            'status' => $this->status,
            'estimated_value' => (float) $this->estimated_value,
            'currency' => $this->currency,
            'notes' => $this->notes,
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
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),
        ];
    }
}
