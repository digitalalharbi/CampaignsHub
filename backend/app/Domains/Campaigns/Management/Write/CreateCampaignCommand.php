<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

/** A campaign to create on a provider account this project has selected — always created PAUSED. */
final class CreateCampaignCommand
{
    public function __construct(
        public readonly string $provider,
        public readonly string $accountExternalId,
        public readonly ?string $managerExternalId,
        public readonly ?string $currency,
        public readonly string $name,
        public readonly string $objective,
        public readonly ?float $dailyBudget,
    ) {}
}
