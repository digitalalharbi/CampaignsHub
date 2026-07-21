<?php

declare(strict_types=1);

namespace App\Domains\CRM\Resources;

use App\Domains\CRM\Models\Opportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Opportunity */
final class OpportunityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'probability' => $this->probability,
            'status' => $this->status,
            'pipeline_id' => $this->pipeline_id,
            'stage_id' => $this->stage_id,
            'stage' => $this->whenLoaded('stage', fn () => [
                'id' => $this->stage->id,
                'name' => $this->stage->name,
            ]),
            'company_id' => $this->company_id,
            'lead_id' => $this->lead_id,
            'owner_id' => $this->owner_id,
            'expected_close_date' => optional($this->expected_close_date)->toDateString(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
