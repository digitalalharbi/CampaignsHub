<?php

declare(strict_types=1);

namespace App\Domains\Metrics\DTO;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One fully-normalized daily metric ready to be upserted into daily_metrics. Carries the original
 * currency/amount + the project-normalized value, plus provenance (timezone, attribution, freshness).
 *
 * `value` is NULLABLE, and that is FX-001's fail-closed half: null means «there is a monetary fact
 * here and no rate anybody can vouch for», which is the only honest answer when a conversion cannot
 * be made. Zero would read as «spent nothing» and the unconverted figure would be the very defect
 * this pipeline exists to stop. `originalAmount` and `originalCurrency` are written regardless, so
 * nothing is lost and the row converts itself the day a rate exists.
 */
final class NormalizedMetric
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $projectId,
        public readonly string $externalAccountId,
        public readonly string $externalCampaignId,
        public readonly string $provider,
        public readonly string $metricKey,
        public readonly Carbon $metricDate,
        public readonly ?float $value,
        public readonly ?string $unifiedCampaignId = null,
        public readonly ?string $connectionId = null,
        public readonly ?string $originalCurrency = null,
        public readonly ?string $projectCurrency = null,
        public readonly ?float $originalAmount = null,
        public readonly ?float $convertedAmount = null,
        public readonly ?float $exchangeRate = null,
        public readonly ?string $rateDate = null,
        public readonly ?string $rateSource = null,
        public readonly ?string $originalTimezone = null,
        public readonly ?string $projectTimezone = null,
        public readonly string $attributionWindow = 'default',
        public readonly string $sourceType = 'api',
        public readonly ?Carbon $dataFreshnessAt = null,
        public readonly ?string $syncRunId = null,
        public readonly ?array $raw = null,
        public readonly bool $isDemo = false,
    ) {}

    /** @return array<string, mixed> row for a bulk upsert (uuid generated up-front; ignored on conflict). */
    public function toRow(Carbon $now): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'connection_id' => $this->connectionId,
            'external_account_id' => $this->externalAccountId,
            'external_campaign_id' => $this->externalCampaignId,
            'unified_campaign_id' => $this->unifiedCampaignId,
            'provider' => $this->provider,
            'metric_key' => $this->metricKey,
            'metric_date' => $this->metricDate->toDateString(),
            'value' => $this->value,
            'original_currency' => $this->originalCurrency,
            'project_currency' => $this->projectCurrency,
            'original_amount' => $this->originalAmount,
            'converted_amount' => $this->convertedAmount,
            'exchange_rate' => $this->exchangeRate,
            'rate_date' => $this->rateDate,
            'rate_source' => $this->rateSource,
            'original_timezone' => $this->originalTimezone,
            'project_timezone' => $this->projectTimezone,
            'attribution_window' => $this->attributionWindow,
            'source_type' => $this->sourceType,
            'data_freshness_at' => $this->dataFreshnessAt,
            'sync_run_id' => $this->syncRunId,
            'raw' => $this->raw !== null ? json_encode($this->raw) : null,
            'is_demo' => $this->isDemo,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
