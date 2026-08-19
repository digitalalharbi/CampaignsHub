<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SNAP-STRUCTURE-RETRY-001 §3 — one Snapchat body, followed to the HTTP response.
 *
 * ## Why this exists beside tests that already assert `data.ad_sets`
 *
 * Those construct `ExternalAdSet` and `ExternalAd` rows by hand, Meta-shaped, and never run the
 * connector or the importer. They prove the CONTROLLER reads rows it was given. They cannot prove
 * that what Snapchat actually sends becomes those rows — and «the connector fetches them» is exactly
 * the proof this phase refuses to accept.
 *
 * So this starts at the platform's own envelopes and ends at the JSON a browser receives:
 *
 *     adsquads[].adsquad → SnapchatConnector → SyncResult → ImportExternalStructure
 *       → external_ad_sets / external_ads / external_creatives
 *       → GET /projects/{p}/campaigns/{c}/structure
 *
 * The Snapchat-specific claim it protects is the one a generic reader gets wrong: **a Snapchat ad
 * does not name its campaign.** It names an `ad_squad_id`, and the campaign is resolved through the
 * squad. An importer that required `campaign_external_id` on the ad would drop every Snapchat ad and
 * report a run that looked healthy.
 */
final class SnapchatStructureReachesTheApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Snap', 'slug' => 'snap-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

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
            'name' => 'RazzahAvenu-shaped',
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

    public function test_a_snapchat_body_becomes_ad_sets_and_ads_in_the_structure_endpoint(): void
    {
        $this->fakeSnapchat();

        $run = app(AccountStructureSyncer::class)->sync($this->account);

        $this->assertSame('success', $run->status, "The sweep did not succeed: {$run->error}");

        $campaign = ExternalCampaign::withoutGlobalScopes()
            ->where('external_id', 'cmp-1')
            ->firstOrFail();

        $this->assertNotNull(
            $campaign->unified_campaign_id,
            'The campaign was discovered but never adopted, so no unified campaign can reach its structure.',
        );

        $res = $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns/{$campaign->unified_campaign_id}/structure")
            ->assertOk()
            ->assertJsonPath('data.state', 'ready');

        /*
         * Found by identity, never by position. The controller orders ad sets by NAME (deliberately —
         * an operator scanning a list wants them alphabetical), so «Jeddah» precedes «Riyadh» and
         * index 0 is `sq-2`. A positional assertion here passed nothing and failed for the wrong
         * reason; it is the test that was wrong about the product, not the other way round.
         */
        $squads = collect($res->json('data.ad_sets'))->keyBy('external_id');

        $this->assertSame('Riyadh — 18-34', $squads['sq-1']['name']);
        $this->assertSame('snapchat', $squads['sq-1']['provider']);
        $this->assertSame('active', $squads['sq-1']['status']);
        // 150_000_000 micro-units is 150.00, not 150000000. Compared loosely on purpose: a whole
        // float serialises to JSON as `150` and decodes as an int, so a strict float assertion here
        // would be testing PHP's JSON encoder rather than the connector's arithmetic.
        $this->assertEquals(150.0, $squads['sq-1']['daily_budget'], 'Micro-units must be divided, not passed through.');
        $this->assertSame('paused', $squads['sq-2']['status'], 'A paused squad is shown, not filtered away.');

        // The ad, reached THROUGH its squad — the placement a Snapchat ad body does not state.
        // Keyed by external id like the squads: `ads` is ordered by name too, and an assertion that
        // depends on ordering fails for a reason that has nothing to do with what it is testing.
        $ads = collect($squads['sq-1']['ads'])->keyBy('external_id');

        $this->assertTrue($ads->has('ad-1'), 'The ad did not reach the response through its squad.');
        $this->assertSame('Swipe up — summer', $ads['ad-1']['name']);
        $this->assertSame('active', $ads['ad-1']['status']);

        // And its creative, joined from the account's creative list because the ad names only an id.
        $this->assertSame('Summer hero', $ads['ad-1']['creative']['name']);

        // Snapchat places every ad by its squad, so nothing may fall into the loose bucket.
        $this->assertSame([], $res->json('data.ads_without_ad_set'));
    }

    /**
     * The second squad and its ad are not lost to the first — a reader that returned only the first
     * page, or only the first parent, would still pass every assertion above.
     */
    public function test_every_ad_squad_and_ad_survives_to_the_response(): void
    {
        $this->fakeSnapchat();

        app(AccountStructureSyncer::class)->sync($this->account);

        $campaign = ExternalCampaign::withoutGlobalScopes()
            ->where('external_id', 'cmp-1')
            ->firstOrFail();

        $res = $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns/{$campaign->unified_campaign_id}/structure")
            ->assertOk();

        $this->assertCount(2, $res->json('data.ad_sets'));
        $this->assertSame(
            ['ad-1', 'ad-2'],
            collect($res->json('data.ad_sets'))->flatMap(fn (array $s) => collect($s['ads'])->pluck('external_id'))->sort()->values()->all(),
        );
    }

    /**
     * Snapchat's host is `adsapi.snapchat.com`, so a `*ads*` pattern matches every call including
     * the campaigns one. Campaigns are claimed first here for that reason — the fixture that got it
     * wrong discovered no parents and skipped every squad and ad, which is a fair imitation of the
     * defect this whole phase is about.
     */
    private function fakeSnapchat(): void
    {
        Http::fake([
            '*/campaigns*' => Http::response(['campaigns' => [
                ['campaign' => ['id' => 'cmp-1', 'name' => 'Summer sale', 'status' => 'ACTIVE', 'objective' => 'WEB_CONVERSION']],
            ]], 200),
            '*/adsquads*' => Http::response(['adsquads' => [
                ['adsquad' => [
                    'id' => 'sq-1', 'campaign_id' => 'cmp-1', 'name' => 'Riyadh — 18-34', 'status' => 'ACTIVE',
                    'optimization_goal' => 'PIXEL_PURCHASE', 'daily_budget_micro' => 150_000_000,
                ]],
                ['adsquad' => [
                    'id' => 'sq-2', 'campaign_id' => 'cmp-1', 'name' => 'Jeddah — 25-44', 'status' => 'PAUSED',
                ]],
            ]], 200),
            '*/creatives*' => Http::response(['creatives' => [
                ['creative' => ['id' => 'cr-1', 'name' => 'Summer hero', 'type' => 'SNAP_AD']],
            ]], 200),
            '*/ads*' => Http::response(['ads' => [
                // Neither ad names a campaign. That is the platform's shape, not an omission.
                ['ad' => ['id' => 'ad-1', 'ad_squad_id' => 'sq-1', 'name' => 'Swipe up — summer', 'status' => 'ACTIVE', 'creative_id' => 'cr-1']],
                ['ad' => ['id' => 'ad-2', 'ad_squad_id' => 'sq-2', 'name' => 'Swipe up — jeddah', 'status' => 'PAUSED', 'creative_id' => 'cr-1']],
            ]], 200),
            '*' => Http::response([], 200),
        ]);
    }
}
