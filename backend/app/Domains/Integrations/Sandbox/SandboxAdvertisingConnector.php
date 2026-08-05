<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Sandbox;

use App\Domains\Integrations\Contracts\AdvertisingConnector;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * Deterministic fake advertising connector for local dev, demos, and contract tests. It returns
 * clearly-labelled sandbox data so nothing is ever mistaken for real platform data. It never talks
 * to the network.
 */
final class SandboxAdvertisingConnector implements AdvertisingConnector
{
    public function key(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox (fake provider)';
    }

    public function status(): ConnectorStatus
    {
        return ConnectorStatus::Connected;
    }

    public function authorizationUrl(string $state): string
    {
        return 'https://sandbox.local/oauth/authorize?state='.urlencode($state);
    }

    public function handleCallback(array $callback): ConnectorStatus
    {
        return ConnectorStatus::Connected;
    }

    public function healthCheck(): HealthResult
    {
        return HealthResult::ok('Sandbox connector is always healthy.');
    }

    public function listAdAccounts(): array
    {
        return [
            ['id' => 'sandbox-act-1', 'name' => 'Sandbox Ad Account', 'currency' => 'SAR', 'sandbox' => true],
        ];
    }

    public function syncCampaigns(string $adAccountId): SyncResult
    {
        return SyncResult::of([
            ['id' => 'sbx-cmp-1', 'ad_account_id' => $adAccountId, 'name' => 'Sandbox Awareness', 'status' => 'active', 'sandbox' => true],
            ['id' => 'sbx-cmp-2', 'ad_account_id' => $adAccountId, 'name' => 'Sandbox Conversions', 'status' => 'paused', 'sandbox' => true],
        ], 'Sandbox campaigns.');
    }

    public function syncAdSets(string $adAccountId): SyncResult
    {
        return SyncResult::of([
            ['external_id' => 'sbx-set-1', 'campaign_external_id' => 'sbx-cmp-1', 'name' => 'Sandbox Broad', 'status' => 'active', 'optimization_goal' => 'reach', 'bid_strategy' => 'lowest_cost', 'daily_budget' => 50.0, 'currency' => 'SAR', 'targeting' => ['countries' => ['SA']], 'raw' => ['sandbox' => true]],
            ['external_id' => 'sbx-set-2', 'campaign_external_id' => 'sbx-cmp-2', 'name' => 'Sandbox Retargeting', 'status' => 'paused', 'optimization_goal' => 'conversions', 'bid_strategy' => 'cost_cap', 'daily_budget' => 120.0, 'currency' => 'SAR', 'targeting' => ['countries' => ['SA']], 'raw' => ['sandbox' => true]],
        ], 'Sandbox ad sets.');
    }

    public function syncAds(string $adAccountId): SyncResult
    {
        return SyncResult::of([
            ['external_id' => 'sbx-ad-1', 'ad_set_external_id' => 'sbx-set-1', 'campaign_external_id' => 'sbx-cmp-1', 'name' => 'Sandbox Video Ad', 'status' => 'active', 'review_status' => 'approved', 'destination_url' => 'https://sandbox.local/landing', 'creative' => ['external_id' => 'sbx-crv-1', 'name' => 'Sandbox Video', 'format' => 'video'], 'raw' => ['sandbox' => true]],
            ['external_id' => 'sbx-ad-2', 'ad_set_external_id' => 'sbx-set-2', 'campaign_external_id' => 'sbx-cmp-2', 'name' => 'Sandbox Image Ad', 'status' => 'paused', 'review_status' => 'pending', 'destination_url' => 'https://sandbox.local/landing', 'creative' => ['external_id' => 'sbx-crv-2', 'name' => 'Sandbox Image', 'format' => 'image'], 'raw' => ['sandbox' => true]],
        ], 'Sandbox ads.');
    }

    public function syncInsights(string $adAccountId, string $from, string $to): SyncResult
    {
        return SyncResult::of([
            ['campaign_id' => 'sbx-cmp-1', 'date' => $from, 'spend' => 100.0, 'impressions' => 10000, 'clicks' => 250, 'sandbox' => true],
            ['campaign_id' => 'sbx-cmp-2', 'date' => $to, 'spend' => 220.5, 'impressions' => 18000, 'clicks' => 410, 'sandbox' => true],
        ], 'Sandbox insights.');
    }
}
