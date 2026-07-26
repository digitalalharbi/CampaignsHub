<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Resources;

use App\Domains\Campaigns\Enums\CampaignPerformanceLabel;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UnifiedCampaign */
final class UnifiedCampaignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'client_display_name' => $this->client_display_name,
            'objective' => $this->objective,
            'status' => $this->status,
            'stage' => $this->stage,
            'performance_label' => $this->performance_label,
            'priority' => $this->priority,
            'needs_attention' => in_array($this->performance_label, CampaignPerformanceLabel::needsAttention(), true),
            'total_budget' => $this->total_budget !== null ? (float) $this->total_budget : null,
            'budget_currency' => $this->budget_currency,
            'starts_on' => optional($this->starts_on)->toDateString(),
            'ends_on' => optional($this->ends_on)->toDateString(),
            'primary_conversion_purpose' => $this->primary_conversion_purpose,
            'attribution_model' => $this->attribution_model,
            'attribution_window' => $this->attribution_window,
            'owner_id' => $this->owner_id,
            'target_kpi' => $this->target_kpi,
            'audience' => $this->audience,
            'regions' => $this->regions,
            'external_campaigns_count' => $this->whenCounted('externalCampaigns'),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
