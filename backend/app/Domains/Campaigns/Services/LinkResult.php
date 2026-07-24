<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;

/**
 * Outcome of a link attempt. `needsConfirmation` signals the caller to return a 409
 * `requires_confirmation` (the external campaign is currently linked to a different unified campaign
 * and moving it must be deliberate).
 */
final class LinkResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $needsConfirmation,
        public readonly ?ExternalCampaign $external,
        public readonly ?string $previousUnifiedCampaignId,
    ) {}

    public static function linked(ExternalCampaign $external, ?string $previous = null): self
    {
        return new self(true, false, $external, $previous);
    }

    public static function needsConfirmation(ExternalCampaign $external): self
    {
        return new self(false, true, $external, $external->unified_campaign_id);
    }
}
