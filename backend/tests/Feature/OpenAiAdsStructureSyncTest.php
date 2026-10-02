<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-OPENAI-001 §§16–17 — the provider's structure through the product's own persistence.
 *
 * `OpenAiAdsConnectorTest` holds what the CONNECTOR returns. This holds what SURVIVES: the shared
 * syncer writes these rows, so what is proved here is that a provider whose hierarchy is named
 * differently — ad groups, not ad sets — lands in the canonical tables with every level pointing at
 * its parent, and that running the same sync twice changes nothing.
 *
 * Idempotency is the one with teeth. `daily_metrics` hangs off these rows, so a second sync that
 * duplicated a campaign would double every figure that campaign reports across the dashboard, the
 * reports and the alerts, with nothing anywhere saying why.
 */
final class OpenAiAdsStructureSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acc', 'slug' => 'acc-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'openai_ads',
            // A key: no refresh token and no expiry, exactly as the connect endpoint stores it.
            tokens: new OAuthTokens('sk-ads-live-0000beef', null, null),
            connectionName: 'ChatGPT Ads',
            credentialType: 'api_key',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'openai_ads',
            'account_type' => 'ad_account',
            'external_id' => 'acct_live_1',
            'name' => 'Acme Riyadh',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    /**
     * **Campaign → ad group → ad, in the canonical tables, each pointing at its parent.**
     *
     * OpenAI calls the middle level an ad GROUP. It is stored where every other provider's middle
     * level is stored, because a second table for one provider's vocabulary is how a report comes to
     * answer one question two ways.
     */
    public function test_the_structure_persists_with_every_level_under_its_parent(): void
    {
        $this->bindToProject();
        $this->providerReturnsStructure();

        app()->call([new SyncAccountStructureJob($this->account->id), 'handle']);

        $campaign = DB::table('external_campaigns')
            ->where('external_account_id', $this->account->id)->where('external_id', 'cmp_1')->first();

        $this->assertNotNull($campaign, 'the campaign did not persist');
        $this->assertSame('Launch', $campaign->name);

        $group = DB::table('external_ad_sets')->where('external_id', 'grp_1')->first();
        $this->assertNotNull($group, 'the ad group did not persist');
        $this->assertSame($campaign->id, $group->external_campaign_id, 'the ad group lost its campaign');

        $ad = DB::table('external_ads')->where('external_id', 'ad_1')->first();
        $this->assertNotNull($ad, 'the ad did not persist');
        $this->assertSame($group->id, $ad->external_ad_set_id, 'the ad lost its ad group');
    }

    /**
     * **The same sync twice changes nothing.**
     *
     * Not «does not error» — the row COUNT, and the identity of each row. A second copy of a
     * campaign is a doubled figure everywhere that campaign appears.
     */
    public function test_a_second_sync_duplicates_nothing(): void
    {
        $this->bindToProject();
        $this->providerReturnsStructure();

        app()->call([new SyncAccountStructureJob($this->account->id), 'handle']);

        $after = [
            'campaigns' => DB::table('external_campaigns')->pluck('id')->sort()->values()->all(),
            'groups' => DB::table('external_ad_sets')->pluck('id')->sort()->values()->all(),
            'ads' => DB::table('external_ads')->pluck('id')->sort()->values()->all(),
        ];

        $this->providerReturnsStructure();
        app()->call([new SyncAccountStructureJob($this->account->id), 'handle']);

        $this->assertSame($after['campaigns'], DB::table('external_campaigns')->pluck('id')->sort()->values()->all());
        $this->assertSame($after['groups'], DB::table('external_ad_sets')->pluck('id')->sort()->values()->all());
        $this->assertSame($after['ads'], DB::table('external_ads')->pluck('id')->sort()->values()->all());
    }

    /**
     * **An account nobody selected is never fetched for.**
     *
     * ACCOUNT-SCOPE-ISOLATION-001 from the other end: discovery catalogues what a key can see, and
     * only a selection makes an account one this product reads. Without the binding the job must
     * return having called the provider zero times — not «return empty», which would still have
     * spent somebody's rate limit on data nobody asked for.
     */
    public function test_an_unselected_account_is_never_fetched_for(): void
    {
        // Deliberately no binding.
        $this->providerReturnsStructure();

        app()->call([new SyncAccountStructureJob($this->account->id), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('external_campaigns')->count());
    }

    private function bindToProject(): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->id,
            'provider' => 'openai_ads',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);
    }

    private function providerReturnsStructure(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [
                    ['id' => 'cmp_1', 'name' => 'Launch', 'status' => 'ACTIVE', 'objective' => 'conversions', 'currency' => 'SAR'],
                ], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_groups')) {
                return Http::response(['data' => [
                    ['id' => 'grp_1', 'campaign_id' => 'cmp_1', 'name' => 'Riyadh', 'status' => 'ACTIVE'],
                ], 'has_more' => false]);
            }

            if (str_contains($url, '/ads')) {
                return Http::response(['data' => [
                    ['id' => 'ad_1', 'ad_group_id' => 'grp_1', 'campaign_id' => 'cmp_1', 'name' => 'Card A', 'status' => 'ACTIVE'],
                ], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1', 'name' => 'Acme Riyadh', 'currency' => 'SAR']);
        });
    }
}
