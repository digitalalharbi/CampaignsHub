<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Resources;

use App\Domains\Campaigns\Models\ExternalCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExternalCampaign */
final class ExternalCampaignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unified_campaign_id' => $this->unified_campaign_id,
            'external_account_id' => $this->external_account_id,
            'provider' => $this->provider,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'status' => $this->status,
            'objective' => $this->objective,
            'daily_budget' => $this->daily_budget !== null ? (float) $this->daily_budget : null,
            'lifetime_budget' => $this->lifetime_budget !== null ? (float) $this->lifetime_budget : null,
            'currency' => $this->currency,
            'is_linked' => $this->unified_campaign_id !== null,
            'linked_at' => optional($this->linked_at)->toIso8601String(),
            'last_synced_at' => optional($this->last_synced_at)->toIso8601String(),
        ];
    }
}
