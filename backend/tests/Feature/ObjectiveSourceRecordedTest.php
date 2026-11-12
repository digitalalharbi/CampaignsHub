<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CampaignObjectiveResolver;
use App\Domains\Campaigns\Services\PlatformObjectiveMap;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * OBJECTIVE-RESULT-001 — the objective gaps the Production diagnosis found, and where it came from.
 *
 * Forty spending campaigns had no objective: Google reports a channel type (SEARCH, PMAX), ChatGPT
 * Ads had no table, Snapchat's APP_PROMOTION was missing. Each is closed only where the platform's
 * own data proves the goal; everything else stays unclassified and says what the platform said.
 */
final class ObjectiveSourceRecordedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Derive Co', 'slug' => 'source-co', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    public function test_the_missing_platform_words_now_translate(): void
    {
        $map = app(PlatformObjectiveMap::class);

        $this->assertSame(CampaignObjective::AppInstalls, $map->resolve('snapchat', 'APP_PROMOTION'));
        $this->assertSame(CampaignObjective::Traffic, $map->resolve('snapchat', 'TRAFFIC'));
        $this->assertSame(CampaignObjective::Conversions, $map->resolve('openai_ads', 'conversions'));
        $this->assertSame(CampaignObjective::Traffic, $map->resolve('openai_ads', 'TRAFFIC'));
        $this->assertNull($map->resolve('openai_ads', 'SOMETHING_ELSE'));
        // A channel type is still not an objective.
        $this->assertNull($map->resolve('google', 'SEARCH'));
        $this->assertNull($map->resolve('google', 'PERFORMANCE_MAX'));
    }

    public function test_google_is_classified_by_its_bidding_goal_only_where_that_goal_is_unambiguous(): void
    {
        $map = app(PlatformObjectiveMap::class);
        $raw = static fn (string $strategy): array => ['raw' => ['campaign' => ['advertisingChannelType' => 'SEARCH', 'biddingStrategyType' => $strategy]]];

        $this->assertSame(CampaignObjective::Sales, $map->resolveFromBidding('google', $raw('TARGET_ROAS')));
        $this->assertSame(CampaignObjective::Sales, $map->resolveFromBidding('google_ads', $raw('MAXIMIZE_CONVERSION_VALUE')));
        $this->assertSame(CampaignObjective::Traffic, $map->resolveFromBidding('google', $raw('TARGET_SPEND')));
        $this->assertSame(CampaignObjective::Awareness, $map->resolveFromBidding('google', $raw('TARGET_IMPRESSION_SHARE')));
        $this->assertSame(CampaignObjective::VideoViews, $map->resolveFromBidding('google', $raw('TARGET_CPV')));

        // A Google «conversion» can be a purchase, a lead or a call; a manual bid states no goal.
        foreach (['MAXIMIZE_CONVERSIONS', 'TARGET_CPA', 'MANUAL_CPC', 'ENHANCED_CPC'] as $strategy) {
            $this->assertNull($map->resolveFromBidding('google', $raw($strategy)), $strategy);
        }
        // Only Google is read this way.
        $this->assertNull($map->resolveFromBidding('meta', $raw('TARGET_ROAS')));
    }

    public function test_a_platform_objective_is_recorded_with_its_source_and_word(): void
    {
        $unified = $this->unified('1KCOFFEE');
        $this->external($unified, 'snapchat', 'TRAFFIC');

        app(CampaignObjectiveResolver::class)->sync($unified);
        $unified->refresh();

        $this->assertSame('traffic', $unified->objective);
        $this->assertSame('platform', $unified->objective_source);
        $this->assertSame('TRAFFIC', $unified->objective_platform_value);
    }

    public function test_a_google_campaign_bid_on_revenue_is_sales_and_says_so(): void
    {
        $unified = $this->unified('Search — ROAS');
        $this->external($unified, 'google', 'SEARCH', ['raw' => ['campaign' => ['biddingStrategyType' => 'TARGET_ROAS']]]);

        app(CampaignObjectiveResolver::class)->sync($unified);
        $unified->refresh();

        $this->assertSame('sales', $unified->objective);
        $this->assertSame('platform', $unified->objective_source);
        $this->assertSame('SEARCH (TARGET_ROAS)', $unified->objective_platform_value);
    }

    public function test_a_google_campaign_bid_on_conversions_stays_unclassified_with_its_word(): void
    {
        $unified = $this->unified('Search — conversions');
        $this->external($unified, 'google', 'SEARCH', ['raw' => ['campaign' => ['biddingStrategyType' => 'MAXIMIZE_CONVERSIONS']]]);

        app(CampaignObjectiveResolver::class)->sync($unified);
        $unified->refresh();

        $this->assertSame('other', $unified->objective);
        $this->assertNotSame('platform', $unified->objective_source);
        $this->assertSame('SEARCH', $unified->objective_platform_value);
    }

    private function unified(string $name): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => CampaignObjective::Other->value,
            'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);
    }

    /** @param array<string, mixed>|null $raw */
    private function external(UnifiedCampaign $unified, string $provider, string $objective, ?array $raw = null): ExternalCampaign
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => 'acct-'.uniqid(), 'name' => ucfirst($provider),
            'currency' => 'SAR', 'status' => 'active',
        ]);

        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(), 'unified_campaign_id' => $unified->id,
            'provider' => $provider, 'external_id' => 'ext-'.uniqid(),
            'name' => 'Platform campaign', 'status' => 'active', 'objective' => $objective, 'raw' => $raw,
        ]);
    }
}
