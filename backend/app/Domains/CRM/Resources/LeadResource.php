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
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),
        ];
    }
}
