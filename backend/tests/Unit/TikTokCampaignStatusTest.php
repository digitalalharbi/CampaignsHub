<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Support\TikTokCampaignStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TIKTOK-CAMPAIGN-STATUS-001 — every value TikTok documents for a campaign's state, mapped onto the canonical status.
 *
 * Source: TikTok's own Business API SDK spec (github.com/tiktok/tiktok-business-api-sdk):
 *  - `yml_files/smart_plus_campaign_status_update.yml`, enum `StatusOptType` — `operation_status` ENABLE / DISABLE / DELETE;
 *  - `yml_files/smart_plus_campaign_get.yml`, enum `CampaignSecondaryStatus` — the delivery states below.
 * TikTok sends `ENABLE` / `DISABLE`, which the generic vocabulary (`enabled`, `disabled`) never matched: every TikTok
 * campaign was `unknown` — 11 on «حلويات برديسي», one of them spending.
 */
final class TikTokCampaignStatusTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string, 2: string}> */
    public static function documented(): array
    {
        return [
            // operation_status alone (StatusOptType)
            'ENABLE' => ['ENABLE', null, 'active'],
            'DISABLE' => ['DISABLE', null, 'paused'],
            'DELETE' => ['DELETE', null, 'archived'],
            // secondary_status (CampaignSecondaryStatus): the delivery state wins over the switch
            'CAMPAIGN_STATUS_ENABLE' => ['ENABLE', 'CAMPAIGN_STATUS_ENABLE', 'active'],
            'CAMPAIGN_STATUS_DISABLE' => ['ENABLE', 'CAMPAIGN_STATUS_DISABLE', 'paused'],
            'CAMPAIGN_STATUS_DELETE' => ['DISABLE', 'CAMPAIGN_STATUS_DELETE', 'archived'],
            'CAMPAIGN_STATUS_BUDGET_EXCEED' => ['ENABLE', 'CAMPAIGN_STATUS_BUDGET_EXCEED', 'paused'],
            'CAMPAIGN_STATUS_ADVERTISER_AUDIT' => ['ENABLE', 'CAMPAIGN_STATUS_ADVERTISER_AUDIT', 'pending'],
            'ADVERTISER_CONTRACT_PENDING' => ['ENABLE', 'ADVERTISER_CONTRACT_PENDING', 'pending'],
            'CAMPAIGN_STATUS_AWAITING_RELEASE' => ['ENABLE', 'CAMPAIGN_STATUS_AWAITING_RELEASE', 'pending'],
            'CAMPAIGN_STATUS_ADVERTISER_AUDIT_DENY' => ['ENABLE', 'CAMPAIGN_STATUS_ADVERTISER_AUDIT_DENY', 'paused'],
            'ADVERTISER_ACCOUNT_PUNISH' => ['ENABLE', 'ADVERTISER_ACCOUNT_PUNISH', 'paused'],
            'CAMPAIGN_STATUS_REVIEW_DISAPPROVED' => ['ENABLE', 'CAMPAIGN_STATUS_REVIEW_DISAPPROVED', 'paused'],
            'CAMPAIGN_STATUS_AD_UNAVAILABLE' => ['ENABLE', 'CAMPAIGN_STATUS_AD_UNAVAILABLE', 'paused'],
            'CAMPAIGN_STATUS_PRODUCT_UNAVAILABLE' => ['ENABLE', 'CAMPAIGN_STATUS_PRODUCT_UNAVAILABLE', 'paused'],
            'CAMPAIGN_STATUS_TIKTOK_SHOP_UNAVAILABLE' => ['ENABLE', 'CAMPAIGN_STATUS_TIKTOK_SHOP_UNAVAILABLE', 'paused'],
            'CAMPAIGN_STATUS_TTS_TT_ASSET_UNAVAILABLE' => ['ENABLE', 'CAMPAIGN_STATUS_TTS_TT_ASSET_UNAVAILABLE', 'paused'],
            'CAMPAIGN_STATUS_TTS_TT_IDENTITY_UNAVAILABLE' => ['ENABLE', 'CAMPAIGN_STATUS_TTS_TT_IDENTITY_UNAVAILABLE', 'paused'],
            'CAMPAIGN_STATUS_IDENTITY_USED_BY_GMV_MAX_AD' => ['ENABLE', 'CAMPAIGN_STATUS_IDENTITY_USED_BY_GMV_MAX_AD', 'paused'],
            'CAMPAIGN_STATUS_IDENTITY_USED_BY_LIVE_GMV_MAX' => ['ENABLE', 'CAMPAIGN_STATUS_IDENTITY_USED_BY_LIVE_GMV_MAX', 'paused'],
            'CAMPAIGN_STATUS_LIVE_GMV_MAX_AUTHORIZATION_CANCEL' => ['ENABLE', 'CAMPAIGN_STATUS_LIVE_GMV_MAX_AUTHORIZATION_CANCEL', 'paused'],
            'CAMPAIGN_STATUS_PRODUCT_GMV_MAX_AUTHORIZATION_CANCEL' => ['ENABLE', 'CAMPAIGN_STATUS_PRODUCT_GMV_MAX_AUTHORIZATION_CANCEL', 'paused'],
            'CAMPAIGN_STATUS_PRODUCT_USED_BY_PRODUCT_GMV_MAX' => ['ENABLE', 'CAMPAIGN_STATUS_PRODUCT_USED_BY_PRODUCT_GMV_MAX', 'paused'],
            // filter-only aggregates, not a state: the switch decides
            'CAMPAIGN_STATUS_ALL' => ['DISABLE', 'CAMPAIGN_STATUS_ALL', 'paused'],
            'CAMPAIGN_STATUS_NOT_DELETE' => ['ENABLE', 'CAMPAIGN_STATUS_NOT_DELETE', 'active'],
        ];
    }

    #[DataProvider('documented')]
    public function test_every_documented_value_maps(?string $operation, ?string $secondary, string $expected): void
    {
        $status = TikTokCampaignStatus::resolve($operation, $secondary);

        $this->assertSame($expected, $status['status']);
        $this->assertNull($status['unmapped']);
    }

    public function test_an_undocumented_value_stays_unknown_and_names_itself(): void
    {
        $neither = TikTokCampaignStatus::resolve('FROZEN_NEW', null);
        $this->assertSame('unknown', $neither['status']);
        $this->assertSame(['operation_status' => 'FROZEN_NEW'], $neither['unmapped']);

        // An undocumented delivery state does not hide a documented switch, but it is still named.
        $secondaryOnly = TikTokCampaignStatus::resolve('DISABLE', 'CAMPAIGN_STATUS_SOMETHING_NEW');
        $this->assertSame('paused', $secondaryOnly['status']);
        $this->assertSame(['secondary_status' => 'CAMPAIGN_STATUS_SOMETHING_NEW'], $secondaryOnly['unmapped']);

        $this->assertSame('unknown', TikTokCampaignStatus::resolve(null, null)['status']);
    }
}
