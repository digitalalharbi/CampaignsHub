<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SNAP-STRUCTURE-RETRY-001 §2 — the counts, and the orphans that make the counts mean something.
 *
 * A sweep reporting «11,686 records» says nothing about shape: every one of those rows could be a
 * campaign, or every ad could be filed under nothing, and the total would read the same. So the
 * report has to state both what was discovered AND whether it was placed — an unplaced row is
 * invisible on every screen that walks the hierarchy downwards, which is most of them.
 *
 * These tests plant one orphan at each level and prove the report names it, because a counter that
 * only ever prints zero is indistinguishable from one that cannot count.
 */
final class HierarchyCountsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'H', 'slug' => 'h-'.uniqid(), 'status' => 'active']);
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
    }

    public function test_a_healthy_hierarchy_is_counted_at_every_level_with_no_orphans(): void
    {
        $campaign = $this->campaign('cmp-1');
        $adSet = $this->adSet('sq-1', $campaign);
        $ad = $this->ad('ad-1', $adSet, $campaign);
        $this->creative('cr-1', $ad, $campaign);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('HIERARCHY')
            ->assertSuccessful();

        $this->assertSame(1, ExternalAdSet::withoutGlobalScopes()->whereNotNull('external_campaign_id')->count());
        $this->assertSame(1, ExternalCreative::withoutGlobalScopes()->whereNotNull('external_ad_id')->count());
    }

    /**
     * The invariant the report leans on: an orphaned ad squad cannot be STORED.
     *
     * `external_ad_sets.external_campaign_id` is NOT NULL with a cascading foreign key. So «orphan ad
     * squads» is not a number to count — it is a state the schema forbids, and rows that would have
     * it are rejected at import, counted as `skipped`, and turn the run `partial_mapping`. Printing a
     * column for it would print 0 for ever whatever happened, which is the shape of reassuring lie
     * this whole ticket is about. This test is what stops that column from being added back.
     */
    public function test_an_ad_squad_with_no_campaign_cannot_be_stored_at_all(): void
    {
        $this->expectException(QueryException::class);

        $this->adSet('sq-orphan', null);
    }

    /**
     * The same for ads: the campaign is NOT NULL, only the ad squad is nullable.
     */
    public function test_an_ad_with_no_campaign_cannot_be_stored_at_all(): void
    {
        $this->expectException(QueryException::class);

        $this->ad('ad-orphan', null, null);
    }

    /**
     * The two placements that CAN go wrong, and the report has to name them rather than
     * fold them into a healthy total.
     */
    public function test_the_two_placements_that_can_go_wrong_are_named(): void
    {
        $campaign = $this->campaign('cmp-1');
        $adSet = $this->adSet('sq-1', $campaign);
        $ad = $this->ad('ad-1', $adSet, $campaign);
        $this->creative('cr-1', $ad, $campaign);

        // An ad hanging off the campaign with no squad — correct on LinkedIn, a defect on Snapchat.
        $this->ad('ad-no-squad', null, $campaign);
        // A creative belonging to no ad — unreachable from any screen that walks downwards.
        $this->creative('cr-no-ad', null, $campaign);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('ads with no ad squad : 1')
            ->expectsOutputToContain('creatives with no ad : 1')
            ->expectsOutputToContain('1 creative(s) belong to no ad.')
            ->assertSuccessful();
    }

    /**
     * A level that discovered nothing is called out, because «0» beside a provider that returned
     * rows is the defect this section exists to make visible.
     */
    public function test_a_level_that_discovered_nothing_is_called_out(): void
    {
        $this->campaign('cmp-1');

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('ad_squads = 0')
            ->expectsOutputToContain('that is a defect')
            ->assertSuccessful();
    }

    /**
     * The counts belong to THIS account. A tenant-wide count would fold in every other connection
     * and answer a question nobody asked — and would have reported a healthy hierarchy for an
     * account that has none.
     */
    public function test_another_accounts_rows_are_not_counted_into_this_ones_hierarchy(): void
    {
        $mine = $this->campaign('cmp-mine');
        $this->adSet('sq-mine', $mine);

        $other = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->account->provider_connection_id,
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act_other',
            'name' => 'Other',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        $theirs = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $other->id,
            'provider' => 'snapchat',
            'external_id' => 'cmp-theirs',
            'name' => 'Theirs',
            'status' => 'active',
        ]);

        $this->adSet('sq-theirs-1', $theirs);
        $this->adSet('sq-theirs-2', $theirs);

        $mineIds = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $this->account->getKey())->pluck('id');

        $this->assertSame(
            1,
            ExternalAdSet::withoutGlobalScopes()->whereIn('external_campaign_id', $mineIds)->count(),
            'The hierarchy is scoped to the account being diagnosed, not to the tenant.',
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function campaign(string $externalId): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->id,
            'provider' => 'snapchat',
            'external_id' => $externalId,
            'name' => "Campaign {$externalId}",
            'status' => 'active',
        ]);
    }

    private function adSet(string $externalId, ?ExternalCampaign $campaign): ExternalAdSet
    {
        return ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_campaign_id' => $campaign?->id,
            'provider' => 'snapchat',
            'external_id' => $externalId,
            'name' => "Squad {$externalId}",
            'status' => 'active',
        ]);
    }

    private function ad(string $externalId, ?ExternalAdSet $adSet, ?ExternalCampaign $campaign): ExternalAd
    {
        return ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_ad_set_id' => $adSet?->id,
            'external_campaign_id' => $campaign?->id,
            'provider' => 'snapchat',
            'external_id' => $externalId,
            'name' => "Ad {$externalId}",
            'status' => 'active',
        ]);
    }

    private function creative(string $externalId, ?ExternalAd $ad, ?ExternalCampaign $campaign): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_campaign_id' => $campaign?->id,
            'external_ad_id' => $ad?->id,
            'provider' => 'snapchat',
            'external_creative_id' => $externalId,
            'name' => "Creative {$externalId}",
            'format' => 'image',
        ]);
    }
}
