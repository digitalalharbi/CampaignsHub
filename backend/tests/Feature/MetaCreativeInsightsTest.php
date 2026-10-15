<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ReportsCreativeInsights;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-CREATIVE-INSIGHTS-001 — Meta's ad-level figures reach the creative the content library reads.
 */
final class MetaCreativeInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_ads_of_one_creative_are_summed_per_day_and_reach_is_not_invented(): void
    {
        $tenant = Tenant::create(['name' => 'Ads', 'slug' => 'ads-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $connection = app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: 'meta',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'meta',
        );

        Http::fake([
            'graph.facebook.com/*/act_1/ads*' => Http::response(['data' => [
                ['id' => 'ad-a', 'creative' => ['id' => 'cr-1']],
                ['id' => 'ad-b', 'creative' => ['id' => 'cr-1']],
                ['id' => 'ad-c', 'creative' => ['id' => 'cr-2']],
            ]]),
            'graph.facebook.com/*/act_1/insights*' => Http::response(['data' => [
                ['ad_id' => 'ad-a', 'date_start' => '2026-10-01', 'spend' => '10', 'impressions' => '1000', 'clicks' => '10',
                    'actions' => [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '4']]],
                ['ad_id' => 'ad-b', 'date_start' => '2026-10-01', 'spend' => '5', 'impressions' => '500', 'clicks' => '5',
                    'actions' => [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '1']]],
                ['ad_id' => 'ad-c', 'date_start' => '2026-10-01', 'spend' => '2', 'impressions' => '90', 'clicks' => '1'],
                ['ad_id' => 'ad-unknown', 'date_start' => '2026-10-01', 'spend' => '99', 'impressions' => '9', 'clicks' => '9'],
            ]]),
        ]);

        $connector = app(AdvertisingConnectorRegistry::class)->get('meta')->withConnection($connection);
        $this->assertInstanceOf(ReportsCreativeInsights::class, $connector);

        $result = $connector->syncCreativeInsights('act_1', '2026-10-01', '2026-10-01');
        $this->assertTrue($result->success);

        $byCreative = [];
        foreach ($result->records as $row) {
            $byCreative[$row['campaign_id']] = $row;
        }

        $this->assertCount(2, $byCreative, 'an ad naming no known creative must not become a row');
        $this->assertSame(15.0, $byCreative['cr-1']['spend']);
        $this->assertSame(1500.0, $byCreative['cr-1']['impressions']);
        $this->assertSame(5.0, $byCreative['cr-1']['conversions']);
        $this->assertSame(2.0, $byCreative['cr-2']['spend']);
        $this->assertArrayNotHasKey('reach', $byCreative['cr-1']);
    }
}
