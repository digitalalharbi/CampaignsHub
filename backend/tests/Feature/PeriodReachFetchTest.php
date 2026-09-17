<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\PeriodReach;
use App\Domains\Metrics\Services\PeriodReachFetcher;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * REACH-PERIOD-001 — each provider is asked for reach over the WHOLE window, at the grains its own
 * documentation says it deduplicates, and the answer is stored against that exact window.
 *
 *   Meta      account + campaign   insights with no daily increment (time_increment=all_days)
 *   Snapchat  campaign only        stats granularity=TOTAL, `uniques` — the ad account entity reports spend only
 *   LinkedIn  account + campaign   adAnalytics timeGranularity=ALL, `approximateMemberReach`, ≤ 92 days,
 *                                  and not for dates older than six months (LinkedIn rounds those to months)
 *   Google    campaign only        GAQL `metrics.unique_users` unsegmented, ≤ 92 days; campaign types without
 *                                  the metric answer nothing and stay not reported
 *   TikTok    not asked            its docs do not state that range reach is deduplicated rather than summed
 */
final class PeriodReachFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $provider, string $externalId, array $campaignIds): ExternalAccount
    {
        foreach (PlatformCredentials::for($provider)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$provider}.{$key}", "test-{$key}");
        }

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $connection = app(TokenVault::class)->open(tenantId: $tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => 'Acct', 'status' => 'active',
            'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => $provider, 'purpose' => 'advertising', 'is_active' => true, 'campaign_management_enabled' => true,
        ]);
        foreach ($campaignIds as $id) {
            ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
                'provider' => $provider, 'external_id' => $id, 'name' => "Campaign {$id}", 'status' => 'active',
            ]);
        }

        return $account;
    }

    private function fetch(ExternalAccount $account, string $from = '2026-09-01', string $to = '2026-09-14'): int
    {
        return app(PeriodReachFetcher::class)->fetch($account, Carbon::parse($from), Carbon::parse($to));
    }

    private function stored(string $grain, string $entity): ?PeriodReach
    {
        return PeriodReach::withoutGlobalScopes()->where('grain', $grain)->where('external_entity_id', $entity)->first();
    }

    public function test_meta_is_asked_for_the_whole_window_at_account_and_campaign_grain(): void
    {
        $account = $this->account('meta', 'act_1', ['cmp-1']);

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return match ($q['level'] ?? null) {
                'account' => Http::response(['data' => [['account_id' => '1', 'reach' => '41000', 'frequency' => '2.5', 'impressions' => '102500']]]),
                'campaign' => Http::response(['data' => [['campaign_id' => 'cmp-1', 'reach' => '30000', 'frequency' => '2.1', 'impressions' => '63000']]]),
                default => Http::response(['error' => ['message' => 'unexpected']], 400),
            };
        });

        $this->fetch($account);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return str_contains($request->url(), 'act_1/insights')
                && ($q['time_increment'] ?? 'all_days') === 'all_days'
                && ! isset($q['breakdowns'])
                && str_contains((string) ($q['fields'] ?? ''), 'reach');
        });

        $this->assertEqualsWithDelta(41000, (float) $this->stored('account', 'act_1')->reach, 0.01);
        $this->assertEqualsWithDelta(2.5, (float) $this->stored('account', 'act_1')->frequency, 0.001);
        $campaign = $this->stored('campaign', 'cmp-1');
        $this->assertEqualsWithDelta(30000, (float) $campaign->reach, 0.01);
        $this->assertNotNull($campaign->external_campaign_id, 'the figure is tied to our campaign so a scope can find it');
        $this->assertSame('2026-09-01', $campaign->date_from->toDateString());
        $this->assertSame('2026-09-14', $campaign->date_to->toDateString());
    }

    public function test_snapchat_is_asked_with_total_granularity_per_campaign_and_never_for_the_account(): void
    {
        $account = $this->account('snapchat', 'snap-acct', ['snap-c1']);

        Http::fake(['adsapi.snapchat.com/*' => Http::response(['total_stats' => [['total_stat' => [
            'id' => 'snap-acct', 'type' => 'AD_ACCOUNT', 'granularity' => 'TOTAL',
            'breakdown_stats' => ['campaign' => [
                ['id' => 'snap-c1', 'type' => 'CAMPAIGN', 'granularity' => 'TOTAL', 'stats' => ['uniques' => 12000, 'frequency' => 1.8, 'impressions' => 21600]],
            ]],
        ]]]])]);

        $this->fetch($account);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return ($q['granularity'] ?? null) === 'TOTAL'
                && ($q['breakdown'] ?? null) === 'campaign'
                && str_contains((string) ($q['fields'] ?? ''), 'uniques');
        });

        $this->assertEqualsWithDelta(12000, (float) $this->stored('campaign', 'snap-c1')->reach, 0.01);
        $this->assertNull($this->stored('account', 'snap-acct'), 'Snapchat reports spend only for the ad account entity');
    }

    public function test_linkedin_is_asked_with_all_granularity_at_account_and_campaign_pivot(): void
    {
        $account = $this->account('linkedin', '5001', ['777']);

        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'pivot=ACCOUNT')
                ? Http::response(['elements' => [['pivotValues' => ['urn:li:sponsoredAccount:5001'], 'impressions' => 9000, 'approximateMemberReach' => 4000]]])
                : Http::response(['elements' => [['pivotValues' => ['urn:li:sponsoredCampaign:777'], 'impressions' => 6000, 'approximateMemberReach' => 3000]]]);
        });

        $this->fetch($account);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'timeGranularity=ALL') && str_contains($r->url(), 'approximateMemberReach'));
        $this->assertEqualsWithDelta(4000, (float) $this->stored('account', '5001')->reach, 0.01);
        $this->assertEqualsWithDelta(3000, (float) $this->stored('campaign', '777')->reach, 0.01);
    }

    public function test_linkedin_is_not_asked_beyond_92_days_or_for_dates_it_rounds_to_months(): void
    {
        $account = $this->account('linkedin', '5001', ['777']);
        Http::fake();

        $this->assertSame(0, $this->fetch($account, '2026-05-01', '2026-09-14'), 'over 92 days');
        $this->assertSame(0, $this->fetch($account, '2026-02-01', '2026-02-28'), 'older than six months');

        Http::assertNothingSent();
        $this->assertSame(0, PeriodReach::withoutGlobalScopes()->count());
    }

    public function test_google_is_asked_for_unique_users_per_campaign_and_a_campaign_without_it_stays_not_reported(): void
    {
        $account = $this->account('google', '1234567890', ['111', '222']);

        Http::fake(['googleads.googleapis.com/*' => Http::response([['results' => [
            ['campaign' => ['id' => '111'], 'metrics' => ['uniqueUsers' => '5000', 'averageImpressionFrequencyPerUser' => 2.2, 'impressions' => '11000']],
            ['campaign' => ['id' => '222'], 'metrics' => ['impressions' => '800']],
        ]]])]);

        $this->fetch($account);

        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['query'] ?? ''), 'metrics.unique_users')
            && ! str_contains((string) ($r->data()['query'] ?? ''), 'SELECT campaign.id, segments.date'));

        $this->assertEqualsWithDelta(5000, (float) $this->stored('campaign', '111')->reach, 0.01);
        $this->assertSame(PeriodReach::NOT_REPORTED, $this->stored('campaign', '222')->state);
        $this->assertNull($this->stored('account', '1234567890'), 'unique users cannot be aggregated to the customer');
    }

    public function test_tiktok_is_not_asked_because_its_range_reach_is_not_documented_as_deduplicated(): void
    {
        $account = $this->account('tiktok', 'adv-1', ['tt-1']);
        Http::fake();

        $this->assertSame(0, $this->fetch($account));
        Http::assertNothingSent();
    }

    public function test_a_failed_request_stores_nothing_so_the_window_is_asked_again(): void
    {
        $account = $this->account('meta', 'act_1', ['cmp-1']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'throttled']], 400)]);

        try {
            $this->fetch($account);
        } catch (\Throwable) {
            // The job retries; what matters is that no «not reported» was remembered.
        }

        $this->assertSame(0, PeriodReach::withoutGlobalScopes()->count());
    }
}
