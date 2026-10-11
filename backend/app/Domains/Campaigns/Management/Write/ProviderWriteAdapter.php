<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

use App\Domains\Integrations\Providers\ApiAdvertisingConnector;

/**
 * CAMPAIGN-MGMT-WRITE-001 — one provider's write vocabulary, translated from the product's.
 *
 * An adapter knows the platform's endpoints and payload shapes and nothing about projects, users or
 * permissions: those are decided before it is called, by `CampaignWriteService`, from rows the
 * project owns. It returns what the provider said; it never claims a success the provider did not
 * report.
 */
interface ProviderWriteAdapter
{
    public function provider(): string;

    /** Null when supported; otherwise one of `WriteRefusal::PROVIDER_UNSUPPORTED` / `NOT_IMPLEMENTED`. */
    public function refusal(WriteLevel $level, WriteAction $action): ?string;

    /** @return list<string> the provider's own bid-strategy names accepted at this level */
    public function bidStrategies(WriteLevel $level): array;

    /** @return list<string> the budget kinds (`daily`, `lifetime`) this level accepts */
    public function budgetKinds(WriteLevel $level): array;

    /** @return list<string> the provider's own objective names a campaign can be CREATED with */
    public function objectives(): array;

    /** @return list<string> the provider's own optimisation goals an ad set can be CREATED with (empty when none is chosen) */
    public function optimizationGoals(): array;

    /** @return list<string> placement families this platform lets an ad set choose (empty when not offered) */
    public function placementFamilies(): array;

    /** Null when campaign creation is supported; otherwise a `WriteRefusal` constant. */
    public function createRefusal(): ?string;

    /** @param  array<string, mixed>  $input  validated by the service for this action */
    public function perform(ApiAdvertisingConnector $connector, WriteTarget $target, WriteAction $action, array $input): WriteOutcome;

    public function create(ApiAdvertisingConnector $connector, CreateCampaignCommand $command): WriteOutcome;
}
