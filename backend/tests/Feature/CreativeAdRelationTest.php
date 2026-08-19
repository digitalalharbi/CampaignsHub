<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CREATIVE-AD-RELATION-001 — one creative, four ads, and a column that remembers one of them.
 *
 * ## What the shape actually is
 *
 * Every provider this application reads sends **one creative per ad**: Snapchat names a single
 * `creative_id`, Meta a single creative, TikTok builds one from the media on the ad. Many ads then
 * share that creative — on the live Snapchat account, 5,706 ads over 1,451 creatives, about four
 * ads each.
 *
 * That is many-to-one, and `external_ads.creative_id` already models it correctly, per ad, with a
 * `belongsTo`. **No association table is needed, and adding one would model something no provider
 * sends.**
 *
 * ## Where the defect is
 *
 * `external_creatives.external_ad_id` is a REVERSE denormalisation of the same fact, written by
 * `creativeFor()` on every upsert, so it keeps whichever ad happened to be imported last. It is not
 * a relation; it is the last writer's name in a field shaped like one.
 *
 * Three readers trust it — `CreativePresenter` twice, and `CreativeRows` for both the `ad_ids`
 * filter and the `ads` aggregate. The aggregate is the visible symptom: `distinct('external_ad_id')`
 * reports 1,451 ads where 5,706 is true, because it is counting creatives through an alias.
 *
 * These tests fail against the column and pass against the relation.
 */
final class CreativeAdRelationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Rel', 'slug' => 'rel-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        foreach (PlatformCredentials::for('snapchat')->requires() as $key) {
            config()->set("ad_platforms.platforms.snapchat.{$key}", "test-{$key}");
        }

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act_snap',
            'name' => 'Snap',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->id,
            'provider' => 'snapchat',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);
    }

    /**
     * The fact the schema already holds, stated so a regression is unmistakable.
     */
    public function test_four_ads_sharing_one_creative_are_all_recorded_against_it(): void
    {
        $this->sync();

        $creative = ExternalCreative::withoutGlobalScopes()
            ->where('external_creative_id', 'cr-1')->firstOrFail();

        $this->assertSame(
            1,
            ExternalCreative::withoutGlobalScopes()->count(),
            'One creative shared by four ads must remain ONE creative row, never one per ad.',
        );

        $this->assertSame(
            ['ad-1', 'ad-2', 'ad-3', 'ad-4'],
            ExternalAd::withoutGlobalScopes()
                ->where('creative_id', $creative->getKey())
                ->orderBy('external_id')->pluck('external_id')->all(),
            'external_ads.creative_id is the real relation and must carry every ad.',
        );
    }

    /**
     * FAIL-FIRST: the reverse column keeps one ad of the four, and it is the last one imported.
     */
    public function test_the_reverse_column_remembers_only_the_last_ad_and_is_not_a_relation(): void
    {
        $this->sync();

        $creative = ExternalCreative::withoutGlobalScopes()
            ->where('external_creative_id', 'cr-1')->firstOrFail();

        $this->assertSame(
            'ad-4',
            $creative->external_ad_id,
            'This is the defect, asserted so it cannot be mistaken for a relation: the column holds '
            .'the LAST ad imported, and says nothing about the other three.',
        );

        $this->assertGreaterThan(
            1,
            ExternalAd::withoutGlobalScopes()->where('creative_id', $creative->getKey())->count(),
            'Four ads point at this creative; the column above names one of them.',
        );
    }

    /**
     * FAIL-FIRST: the inverse relation must exist so readers have something true to use.
     */
    public function test_a_creative_can_name_every_ad_that_carries_it(): void
    {
        $this->sync();

        $creative = ExternalCreative::withoutGlobalScopes()
            ->where('external_creative_id', 'cr-1')->firstOrFail();

        $this->assertSame(
            ['ad-1', 'ad-2', 'ad-3', 'ad-4'],
            $creative->ads()->withoutGlobalScopes()->orderBy('external_id')->pluck('external_id')->all(),
            'ExternalCreative::ads() is the honest inverse of ExternalAd::creative().',
        );
    }

    /**
     * Idempotency: a second sweep changes no counts and adds no duplicate creative.
     */
    public function test_a_second_sweep_adds_no_creative_and_no_relation_drifts(): void
    {
        $this->sync();
        $this->sync();

        $creative = ExternalCreative::withoutGlobalScopes()
            ->where('external_creative_id', 'cr-1')->firstOrFail();

        $this->assertSame(1, ExternalCreative::withoutGlobalScopes()->count());
        $this->assertSame(4, ExternalAd::withoutGlobalScopes()->where('creative_id', $creative->getKey())->count());
    }

    /**
     * Isolation: another project's ads never appear against this project's creative, even when the
     * provider hands out the same creative id — which it does, because ids are per-account.
     */
    public function test_another_projects_ads_never_attach_to_this_projects_creative(): void
    {
        $this->sync();

        $otherWs = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C2', 'slug' => 'c2-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $otherWs->id, 'name' => 'P2', 'status' => 'active',
        ]);

        $otherCampaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $otherProject->id,
            'external_account_id' => $this->account->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-other', 'name' => 'Other', 'status' => 'active',
        ]);
        $otherSet = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $otherProject->id,
            'external_campaign_id' => $otherCampaign->id, 'provider' => 'snapchat',
            'external_id' => 'sq-other', 'name' => 'Other squad', 'status' => 'active',
        ]);
        $otherCreative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $otherProject->id,
            'external_campaign_id' => $otherCampaign->id, 'provider' => 'snapchat',
            // The SAME provider creative id — ids are per-account, so this collision is realistic.
            'external_creative_id' => 'cr-1', 'name' => 'Other creative', 'format' => 'image',
        ]);
        ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $otherProject->id,
            'external_campaign_id' => $otherCampaign->id, 'external_ad_set_id' => $otherSet->id,
            'creative_id' => $otherCreative->id, 'provider' => 'snapchat',
            'external_id' => 'ad-other', 'name' => 'Other ad', 'status' => 'active',
        ]);

        $mine = ExternalCreative::withoutGlobalScopes()
            ->where('project_id', $this->project->id)->where('external_creative_id', 'cr-1')->firstOrFail();

        $this->assertSame(
            ['ad-1', 'ad-2', 'ad-3', 'ad-4'],
            $mine->ads()->withoutGlobalScopes()->orderBy('external_id')->pluck('external_id')->all(),
            'The other project shares a provider creative id and must not share the relation.',
        );
    }

    private function sync(): void
    {
        Http::fake([
            '*/campaigns*' => Http::response(['campaigns' => [
                ['campaign' => ['id' => 'cmp-1', 'name' => 'Summer', 'status' => 'ACTIVE', 'objective' => 'WEB_CONVERSION']],
            ]], 200),
            '*/adsquads*' => Http::response(['adsquads' => [
                ['adsquad' => ['id' => 'sq-1', 'campaign_id' => 'cmp-1', 'name' => 'Squad', 'status' => 'ACTIVE']],
            ]], 200),
            '*/creatives*' => Http::response(['creatives' => [
                ['creative' => ['id' => 'cr-1', 'name' => 'Shared hero', 'type' => 'SNAP_AD']],
            ]], 200),
            // Four ads, one creative between them — the live account's shape at ~4 ads per creative.
            '*/ads*' => Http::response(['ads' => array_map(static fn (int $i): array => ['ad' => [
                'id' => "ad-{$i}", 'ad_squad_id' => 'sq-1', 'name' => "Ad {$i}",
                'status' => 'ACTIVE', 'creative_id' => 'cr-1',
            ]], [1, 2, 3, 4])], 200),
            '*' => Http::response([], 200),
        ]);

        app(AccountStructureSyncer::class)->sync($this->account);
    }
}
