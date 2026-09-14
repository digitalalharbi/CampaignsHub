<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ReportsEntityGrains;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GADS-ENTITY-GRAIN-001 — Google reports the ad and ad-group rungs, and the product never asked.
 *
 * ## Why this row exists
 *
 * `AccountMetricsSyncer` fills `entity_daily_metrics` for any connector that declares it can answer
 * the grain. Google did not declare it, so an operator's ad groups and ads showed «—» for spend,
 * clicks, CPC, CPM and CPA — figures Google reports perfectly well. The codebase has already written
 * that sentence about Meta: «the product printed our silence as the platform's».
 *
 * It also decides whether a Google CREATIVE can show a figure at all. Nothing writes
 * `creative_daily_metrics` for Google, so `CreativeMetrics` sums the ads that carry a creative — and
 * that sum has nothing to work with until this table is filled.
 *
 * ## What is asserted, and what cannot be
 *
 * The QUERY and the MAPPING, against a recorded `searchStream` body in Google's documented shape. No
 * Google account is reachable from this install, so nothing here proves Google answers this way —
 * the same standing this repo gave Meta's ad-set grain, recorded as IMPLEMENTED_NOT_VERIFIED rather
 * than dressed up as verification.
 */
final class GoogleAdsEntityGrainTest extends TestCase
{
    use RefreshDatabase;

    /** A `searchStream` answer in Google's own shape: an array of chunks, each carrying results. */
    private function body(): array
    {
        return [[
            'results' => [
                [
                    'adGroupAd' => ['ad' => ['id' => '555']],
                    'adGroup' => ['id' => '222'],
                    'campaign' => ['id' => '111'],
                    'segments' => ['date' => '2026-08-01'],
                    'metrics' => [
                        'costMicros' => '12340000',
                        'impressions' => '900',
                        'clicks' => '30',
                        'conversions' => 4.0,
                        'conversionsValue' => 260.0,
                    ],
                ],
            ],
        ]];
    }

    public function test_the_ad_grain_is_asked_of_google_and_mapped_from_its_answer(): void
    {
        $connector = $this->google();

        Http::fake(['googleads.googleapis.com/*' => Http::response($this->body())]);

        $result = $connector->entityInsights('111-111-1111', ReportsEntityGrains::AD, [], '2026-08-01', '2026-08-02');

        $this->assertTrue($result->success, 'the grain sweep failed: '.($result->message ?? ''));
        $this->assertCount(1, $result->records);

        $row = $result->records[0];
        $this->assertSame('555', $row['entity_id'], 'the AD id is the entity, not the ad group');
        $this->assertSame('222', $row['ad_set_id']);
        $this->assertSame('111', $row['campaign_id']);
        $this->assertSame('2026-08-01', $row['date']);

        /* Google reports money in micros, and this product stores currency units. */
        $this->assertSame(12.34, $row['spend']);
        $this->assertSame(900.0, $row['impressions']);
        $this->assertSame(30.0, $row['clicks']);
        $this->assertSame(260.0, $row['revenue']);
    }

    /** The ad-GROUP rung is the same query one level up, and must key on the ad group. */
    public function test_the_ad_set_grain_keys_on_the_ad_group(): void
    {
        $connector = $this->google();

        Http::fake(['googleads.googleapis.com/*' => Http::response($this->body())]);

        $result = $connector->entityInsights('111-111-1111', ReportsEntityGrains::AD_SET, [], '2026-08-01', '2026-08-02');

        $this->assertSame('222', $result->records[0]['entity_id']);
    }

    /**
     * A metric Google did not return stays ABSENT.
     *
     * The campaign sweep beside this one filters nulls for the same reason: a key that arrives as 0
     * tells the reader the platform measured nothing, which is a different statement from «this was
     * not reported» and is the fabricated zero this product refuses.
     */
    public function test_a_metric_google_did_not_return_is_absent_rather_than_zero(): void
    {
        $connector = $this->google();

        Http::fake(['googleads.googleapis.com/*' => Http::response([[
            'results' => [[
                'adGroupAd' => ['ad' => ['id' => '555']],
                'adGroup' => ['id' => '222'],
                'campaign' => ['id' => '111'],
                'segments' => ['date' => '2026-08-01'],
                'metrics' => ['costMicros' => '1000000'],
            ]],
        ]])]);

        $row = $connector->entityInsights('111-111-1111', ReportsEntityGrains::AD, [], '2026-08-01', '2026-08-02')->records[0];

        $this->assertSame(1.0, $row['spend']);
        $this->assertArrayNotHasKey('clicks', $row, 'an unreported metric became a zero');
        $this->assertArrayNotHasKey('video_views', $row);
    }

    /**
     * A refusal is kept as its MESSAGE.
     *
     * An empty grain has two entirely different causes — nothing swept yet, or the platform refused —
     * and only the second tells an operator what to do about it.
     */
    public function test_a_refusal_is_reported_with_its_reason(): void
    {
        $connector = $this->google();

        Http::fake(['googleads.googleapis.com/*' => Http::response(['error' => ['message' => 'USER_PERMISSION_DENIED']], 403)]);

        $result = $connector->entityInsights('111-111-1111', ReportsEntityGrains::AD, [], '2026-08-01', '2026-08-02');

        $this->assertFalse($result->success);
        $this->assertNotNull($connector->lastEntityFailure(), 'the run log cannot say why the table is empty');
    }

    private function google(): ReportsEntityGrains
    {
        $tenant = Tenant::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        foreach (PlatformCredentials::for('google')->requires() as $key) {
            config()->set("ad_platforms.platforms.google.{$key}", "test-{$key}");
        }

        /* A real token in the vault, because the connector refuses to call without one. */
        $connection = app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: 'google',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'google',
        );

        $connector = app(AdvertisingConnectorRegistry::class)->get('google')->withConnection($connection);

        $this->assertInstanceOf(
            ReportsEntityGrains::class,
            $connector,
            'Google does not declare it can answer the grain, so the syncer will never ask it',
        );

        return $connector;
    }
}
