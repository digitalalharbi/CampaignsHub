<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
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
 * **The Snapchat shape is proven**: its ads name a single `creative_id`, and many ads share it — on
 * the live account, 5,706 ads over 1,451 creatives, about four each. That is many-to-one, and
 * `external_ads.creative_id` already models it correctly, per ad, with a `belongsTo`.
 *
 * Other adapters emit at most one creative per ad row; Google Ads and LinkedIn emit none.
 * **Platform-native capabilities are not claimed** — those would need each API's contract read. What
 * follows from what IS known is enough: no association table is needed, and adding one would model
 * something no adapter here produces.
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

    // ── The relation itself ───────────────────────────────────────────────────────────────────

    /**
     * The fact the schema already holds, stated so a regression is unmistakable.
     */
    public function test_four_ads_sharing_one_creative_are_all_recorded_against_it(): void
    {
        $this->sync();

        $creative = $this->sharedCreative();

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

        $this->assertSame(
            'ad-4',
            $this->sharedCreative()->external_ad_id,
            'This is the defect, asserted so it cannot be mistaken for a relation: the column holds '
            .'the LAST ad imported, and says nothing about the other three.',
        );
    }

    /**
     * FAIL-FIRST: the inverse relation must exist so readers have something true to use.
     */
    public function test_a_creative_can_name_every_ad_that_carries_it(): void
    {
        $this->sync();

        $this->assertSame(
            ['ad-1', 'ad-2', 'ad-3', 'ad-4'],
            $this->sharedCreative()->ads()->withoutGlobalScopes()->orderBy('external_id')->pluck('external_id')->all(),
            'ExternalCreative::ads() is the honest inverse of ExternalAd::creative().',
        );
    }

    public function test_a_second_sweep_adds_no_creative_and_no_relation_drifts(): void
    {
        $this->sync();
        $this->sync();

        $this->assertSame(1, ExternalCreative::withoutGlobalScopes()->count());
        $this->assertSame(
            4,
            ExternalAd::withoutGlobalScopes()->where('creative_id', $this->sharedCreative()->getKey())->count(),
        );
    }

    // ── The readers, exercised directly ───────────────────────────────────────────────────────
    //
    // The four tests above prove the SHAPE. They execute none of `CreativeRows`, so they are not
    // evidence for it: the filter and the options list are separate code paths and get their own
    // failing-first cases here. Both fail against `external_ad_id` and pass against the relation.

    /**
     * FAIL-FIRST: filtering by ANY of the four ads must find the creative they share.
     *
     * Against the old column this passed for `ad-4` and returned nothing for the other three — and
     * an empty result reads as «this ad has no creatives», which is a sentence the data disproves.
     */
    public function test_filtering_by_each_of_the_four_ads_returns_the_shared_creative(): void
    {
        $this->sync();

        $creative = $this->sharedCreative();

        foreach (['ad-1', 'ad-2', 'ad-3', 'ad-4'] as $adExternalId) {
            $query = ExternalCreative::withoutGlobalScopes()->where('project_id', $this->project->id);
            app(CreativeRows::class)->applyFilters($query, ['ad_ids' => [$adExternalId]]);

            $this->assertSame(
                [$creative->getKey()],
                $query->pluck('id')->all(),
                "Filtering by {$adExternalId} must find the creative that ad carries.",
            );
        }
    }

    /**
     * FAIL-FIRST: the ads filter offers every ad, not one per creative.
     */
    public function test_the_ads_filter_offers_all_four_ad_ids(): void
    {
        $this->sync();

        $options = app(CreativeRows::class)->filterOptions(
            fn () => ExternalCreative::withoutGlobalScopes()->where('project_id', $this->project->id),
        );

        $this->assertSame(
            ['ad-1', 'ad-2', 'ad-3', 'ad-4'],
            $options['ads'],
            'The option list read `distinct(external_ad_id)`, which offers one ad per creative — so '
            .'three of these four were unselectable and the control looked like a complete list.',
        );
    }

    /**
     * A different project's ad id must not select this project's creative, and must not appear in
     * its options — provider ids are per-account, so the collision below is realistic.
     */
    public function test_another_projects_ads_do_not_leak_into_this_projects_filter_or_options(): void
    {
        $this->sync();
        [$otherProject, $otherCreative] = $this->otherProjectWithCollidingIds();

        $query = ExternalCreative::withoutGlobalScopes()->where('project_id', $this->project->id);
        app(CreativeRows::class)->applyFilters($query, ['ad_ids' => ['ad-other']]);

        $this->assertSame([], $query->pluck('id')->all(), "Another project's ad must select nothing here.");

        $options = app(CreativeRows::class)->filterOptions(
            fn () => ExternalCreative::withoutGlobalScopes()->where('project_id', $this->project->id),
        );

        $this->assertNotContains('ad-other', $options['ads']);

        // And the relation itself stays on its own side of the boundary.
        $this->assertSame(
            ['ad-1', 'ad-2', 'ad-3', 'ad-4'],
            $this->sharedCreative()->ads()->withoutGlobalScopes()->orderBy('external_id')->pluck('external_id')->all(),
        );
        $this->assertSame(
            ['ad-other'],
            $otherCreative->ads()->withoutGlobalScopes()->pluck('external_id')->all(),
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function sharedCreative(): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()
            ->where('project_id', $this->project->id)
            ->where('external_creative_id', 'cr-1')
            ->firstOrFail();
    }

    /**
     * A second project holding the SAME provider ids — creative `cr-1` included.
     *
     * @return array{0: Project, 1: ExternalCreative}
     */
    private function otherProjectWithCollidingIds(): array
    {
        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C2', 'slug' => 'c2-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P2', 'status' => 'active',
        ]);

        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $this->account->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-other', 'name' => 'Other', 'status' => 'active',
        ]);
        $set = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_campaign_id' => $campaign->id, 'provider' => 'snapchat',
            'external_id' => 'sq-other', 'name' => 'Other squad', 'status' => 'active',
        ]);
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_campaign_id' => $campaign->id, 'provider' => 'snapchat',
            'external_creative_id' => 'cr-1', 'name' => 'Other creative', 'format' => 'image',
        ]);
        ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_campaign_id' => $campaign->id, 'external_ad_set_id' => $set->id,
            'creative_id' => $creative->id, 'provider' => 'snapchat',
            'external_id' => 'ad-other', 'name' => 'Other ad', 'status' => 'active',
        ]);

        return [$project, $creative];
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
