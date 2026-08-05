<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Resources;

use App\Domains\Campaigns\Enums\CampaignPerformanceLabel;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CampaignObjectiveResolver;
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
            /*
             * Where the classification came from, beside the classification itself
             * (REPORT-OBJECTIVE-002). This one field decides whether the campaign's spend reaches a
             * client's cost per order, so «sales» on its own is not enough to act on — a screen has
             * to be able to say whether that was the platform's word, a person's correction, or a
             * default nobody has looked at.
             */
            'objective_provenance' => app(CampaignObjectiveResolver::class)->provenance($this->resource),
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
            // Taxonomy-backed multi-select vocabularies (nullable jsonb) — exposed so the edit form round-trips.
            'regions' => $this->regions,
            'platforms' => $this->platforms,
            'audiences' => $this->audiences,
            'conversion_events' => $this->conversion_events,
            'creative_types' => $this->creative_types,
            'tags' => $this->tags,
            'external_campaigns_count' => $this->whenCounted('externalCampaigns'),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
