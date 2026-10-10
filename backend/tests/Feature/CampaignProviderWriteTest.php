<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CAMPAIGN-MGMT-WRITE-001 — every provider write goes out in the provider's own shape, only through
 * every gate, and only what the provider confirmed reaches the mirror and the change history.
 *
 * Live round-trips need real platform apps and accounts (BLOCKED_EXTERNAL_CREDENTIALS); these pin the
 * contract each adapter speaks against the platform's documented request and response shapes.
 */
final class CampaignProviderWriteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'W', 'slug' => 'w', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@w.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Launch', 'objective' => 'sales', 'status' => 'active']);
    }

    // ── Meta ──────────────────────────────────────────────────────────────────────────────────

    public function test_meta_pause_sets_the_node_status_and_the_confirmed_change_reaches_mirror_and_history(): void
    {
        $this->configure('meta');
        [, $campaign] = $this->tree('meta', 'act_1');
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $this->write('campaign', $campaign->id, 'pause')->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.mirror.status', 'paused');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/'.$campaign->external_id) && $r['status'] === 'PAUSED');
        $this->assertSame('paused', $campaign->fresh()->status);
        $log = AuditLog::query()->where('action', 'campaign.provider_write')->sole();
        $this->assertSame((string) $this->campaign->id, (string) $log->entity_id);
        $this->assertSame(['status' => 'active'], $log->before['values']);
        $this->assertSame('pause', $log->before['action']);
    }

    public function test_meta_budget_is_sent_in_the_currency_s_smallest_unit(): void
    {
        $this->configure('meta');
        [, , $adSet] = $this->tree('meta', 'act_1');
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $this->write('ad_set', $adSet->id, 'budget', ['daily_budget' => 150.5])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/'.$adSet->external_id) && $r['daily_budget'] === 15050);
        $this->assertSame(150.5, (float) $adSet->fresh()->daily_budget);
    }

    public function test_meta_duplicate_asks_for_a_paused_deep_copy_and_returns_the_copy_s_id(): void
    {
        $this->configure('meta');
        [, $campaign] = $this->tree('meta', 'act_1');
        Http::fake(['graph.facebook.com/*' => Http::response(['copied_campaign_id' => '2390001'])]);

        $this->write('campaign', $campaign->id, 'duplicate')->assertOk()->assertJsonPath('data.new_external_id', '2390001');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/'.$campaign->external_id.'/copies') && $r['deep_copy'] === true && $r['status_option'] === 'PAUSED');
    }

    public function test_a_provider_refusal_is_returned_verbatim_and_nothing_is_mirrored(): void
    {
        $this->configure('meta');
        [, $campaign] = $this->tree('meta', 'act_1');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => '(#100) Budget is too low', 'code' => 100, 'fbtrace_id' => 'TR1']], 400)]);

        $this->write('campaign', $campaign->id, 'budget', ['daily_budget' => 1])
            ->assertStatus(422)
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.refusal', 'provider_refused');

        $this->assertStringContainsString('Budget is too low', (string) AuditLog::query()->where('action', 'campaign.provider_write_refused')->sole()->reason);
        $this->assertNull($campaign->fresh()->daily_budget);
    }

    public function test_meta_create_makes_a_paused_campaign_mirrors_it_linked_and_launches_only_when_asked(): void
    {
        $this->configure('meta');
        $account = $this->account('meta', 'act_77');
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['id' => '120200'])->push(['success' => true])]);

        $this->actingAs($this->owner, 'sanctum')->postJson($this->url('provider-campaigns'), [
            'external_account_id' => $account->id, 'objective' => 'OUTCOME_SALES', 'daily_budget' => 200, 'launch' => true,
        ])->assertCreated()->assertJsonPath('data.external_id', '120200')->assertJsonPath('data.launched', true);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/act_77/campaigns') && $r['status'] === 'PAUSED' && $r['objective'] === 'OUTCOME_SALES' && $r['daily_budget'] === 20000);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/120200') && $r['status'] === 'ACTIVE');
        $mirror = ExternalCampaign::query()->where('external_id', '120200')->sole();
        $this->assertSame((string) $this->campaign->id, (string) $mirror->unified_campaign_id);
        $this->assertSame('active', $mirror->status);
    }

    // ── Google Ads ────────────────────────────────────────────────────────────────────────────

    public function test_google_pause_is_a_status_update_with_a_mask_through_the_manager(): void
    {
        $this->configure('google');
        [, $campaign] = $this->tree('google', '123-456-7890', manager: '999-000-1111');
        Http::fake(['googleads.googleapis.com/*' => Http::response(['results' => [['resourceName' => 'x']]])]);

        $this->write('campaign', $campaign->id, 'pause')->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'customers/1234567890/campaigns:mutate')
            && $r['operations'][0]['update']['status'] === 'PAUSED'
            && $r['operations'][0]['update']['resourceName'] === 'customers/1234567890/campaigns/'.$campaign->external_id
            && $r['operations'][0]['updateMask'] === 'status'
            && $r->header('login-customer-id') === ['9990001111']);
    }

    public function test_google_delete_needs_a_confirmation_and_is_a_remove_operation(): void
    {
        $this->configure('google');
        [, $campaign] = $this->tree('google', '1234567890');
        Http::fake(['googleads.googleapis.com/*' => Http::response(['results' => [['resourceName' => 'x']]])]);

        $this->write('campaign', $campaign->id, 'delete')->assertStatus(422);
        Http::assertNothingSent();

        $this->write('campaign', $campaign->id, 'delete', ['confirm' => true])->assertOk();
        Http::assertSent(fn (Request $r) => $r['operations'][0]['remove'] === 'customers/1234567890/campaigns/'.$campaign->external_id);
        $this->assertSame('deleted', $campaign->fresh()->status);
    }

    public function test_google_refuses_to_change_a_shared_budget_from_one_campaign(): void
    {
        $this->configure('google');
        [, $campaign] = $this->tree('google', '1234567890');
        Http::fake(['googleads.googleapis.com/*' => Http::response(['results' => [[
            'campaign' => ['campaignBudget' => 'customers/1234567890/campaignBudgets/55'],
            'campaignBudget' => ['explicitlyShared' => true],
        ]]])]);

        $this->write('campaign', $campaign->id, 'budget', ['daily_budget' => 300])->assertStatus(422);

        Http::assertSentCount(1); // the read only — no mutate
    }

    public function test_an_action_the_platform_forbids_is_refused_without_a_call(): void
    {
        $this->configure('google');
        [, $campaign] = $this->tree('google', '1234567890');
        Http::fake();

        $this->write('campaign', $campaign->id, 'archive', ['confirm' => true])->assertStatus(409)->assertJsonPath('data.refusal', 'provider_unsupported');
        $this->write('campaign', $campaign->id, 'duplicate')->assertStatus(409)->assertJsonPath('data.refusal', 'provider_unsupported');
        Http::assertNothingSent();
    }

    // ── Snapchat ──────────────────────────────────────────────────────────────────────────────

    public function test_snapchat_reads_the_whole_entity_and_puts_it_back_with_one_field_changed(): void
    {
        $this->configure('snapchat');
        [, $campaign] = $this->tree('snapchat', 'sc-acc');
        Http::fake([
            'adsapi.snapchat.com/v1/campaigns/*' => Http::response(['request_status' => 'SUCCESS', 'campaigns' => [['sub_request_status' => 'SUCCESS', 'campaign' => [
                'id' => $campaign->external_id, 'name' => 'Spring', 'ad_account_id' => 'sc-acc', 'status' => 'ACTIVE',
                'start_time' => '2026-09-01T00:00:00.000Z', 'created_at' => '2026-08-30T00:00:00.000Z',
            ]]]]),
            'adsapi.snapchat.com/v1/adaccounts/*' => Http::response(['request_status' => 'SUCCESS', 'campaigns' => [['sub_request_status' => 'SUCCESS', 'campaign' => ['id' => $campaign->external_id]]]]),
        ]);

        $this->write('campaign', $campaign->id, 'pause')->assertOk();

        Http::assertSent(function (Request $r) use ($campaign): bool {
            $sent = $r['campaigns'][0] ?? [];

            return $r->method() === 'PUT' && str_ends_with($r->url(), 'adaccounts/sc-acc/campaigns')
                && $sent['status'] === 'PAUSED' && $sent['start_time'] === '2026-09-01T00:00:00.000Z'
                && ! array_key_exists('created_at', $sent) && $sent['id'] === $campaign->external_id;
        });
    }

    public function test_snapchat_reports_a_refused_item_inside_a_successful_envelope(): void
    {
        $this->configure('snapchat');
        [, $campaign] = $this->tree('snapchat', 'sc-acc');
        Http::fake([
            'adsapi.snapchat.com/v1/campaigns/*' => Http::response(['request_status' => 'SUCCESS', 'campaigns' => [['campaign' => ['id' => $campaign->external_id, 'status' => 'ACTIVE']]]]),
            'adsapi.snapchat.com/v1/adaccounts/*' => Http::response(['request_status' => 'SUCCESS', 'campaigns' => [['sub_request_status' => 'ERROR', 'sub_request_error_reason' => 'Daily budget below minimum']]]),
        ]);

        $this->write('campaign', $campaign->id, 'budget', ['daily_budget' => 1])->assertStatus(422)->assertJsonPath('data.ok', false);
        $this->assertSame('active', $campaign->fresh()->status);
    }

    // ── TikTok ────────────────────────────────────────────────────────────────────────────────

    public function test_tiktok_status_change_names_the_advertiser_and_an_id_list(): void
    {
        $this->configure('tiktok');
        [, , $adSet] = $this->tree('tiktok', '7000001');
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK', 'request_id' => 'rq-1', 'data' => []])]);

        $this->write('ad_set', $adSet->id, 'pause')->assertOk()->assertJsonPath('data.request_id', 'rq-1');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'adgroup/status/update/') && $r['advertiser_id'] === '7000001' && $r['adgroup_ids'] === [$adSet->external_id] && $r['operation_status'] === 'DISABLE');
    }

    public function test_tiktok_failure_inside_http_200_is_a_refusal(): void
    {
        $this->configure('tiktok');
        [, $campaign] = $this->tree('tiktok', '7000001');
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 40002, 'message' => 'Budget must be at least 50', 'request_id' => 'rq-2'])]);

        $this->write('campaign', $campaign->id, 'budget', ['daily_budget' => 5])->assertStatus(422)->assertJsonPath('data.request_id', 'rq-2');
    }

    // ── The hierarchy: ad sets, ads, targeting, placements, creatives, destinations ───────────

    public function test_meta_creates_a_paused_ad_set_under_the_campaign_and_mirrors_it_at_once(): void
    {
        $this->configure('meta');
        [, $campaign] = $this->tree('meta', 'act_5');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '6100'])]);

        $this->write('campaign', $campaign->id, 'create_ad_set', [
            'name' => 'KSA — 25-44', 'optimization_goal' => 'OFFSITE_CONVERSIONS', 'daily_budget' => 120, 'countries' => ['SA', 'AE'],
        ])->assertOk()->assertJsonPath('data.new_external_id', '6100');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/act_5/adsets') && $r['campaign_id'] === $campaign->external_id
            && $r['status'] === 'PAUSED' && $r['daily_budget'] === 12000 && $r['targeting']['geo_locations']['countries'] === ['SA', 'AE']);
        $child = ExternalAdSet::query()->where('external_id', '6100')->sole();
        $this->assertSame('paused', $child->status);
        $this->assertSame((string) $campaign->id, (string) $child->external_campaign_id);
    }

    public function test_meta_targeting_is_read_first_so_the_keys_it_does_not_change_survive(): void
    {
        $this->configure('meta');
        [, , $adSet] = $this->tree('meta', 'act_5');
        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['targeting' => ['geo_locations' => ['countries' => ['SA']], 'flexible_spec' => [['interests' => [['id' => '6003', 'name' => 'Shopping']]]], 'publisher_platforms' => ['instagram']]])
                ->push(['success' => true]),
        ]);

        $this->write('ad_set', $adSet->id, 'targeting', ['countries' => ['SA', 'KW'], 'age_min' => 25, 'age_max' => 44, 'genders' => 'female'])->assertOk();

        Http::assertSent(function (Request $r): bool {
            $t = $r['targeting'] ?? null;

            return $r->method() === 'POST' && is_array($t) && $t['geo_locations']['countries'] === ['SA', 'KW'] && $t['age_min'] === 25
                && $t['genders'] === [2] && $t['flexible_spec'][0]['interests'][0]['id'] === '6003' && $t['publisher_platforms'] === ['instagram'];
        });
        $this->assertSame(['SA', 'KW'], $adSet->fresh()->targeting['countries']);
        $this->assertSame('25-44', $adSet->fresh()->targeting['age']);
    }

    public function test_meta_automatic_placements_clear_every_placement_key(): void
    {
        $this->configure('meta');
        [, , $adSet] = $this->tree('meta', 'act_5');
        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['targeting' => ['geo_locations' => ['countries' => ['SA']], 'publisher_platforms' => ['facebook'], 'facebook_positions' => ['feed']]])
                ->push(['success' => true]),
        ]);

        $this->write('ad_set', $adSet->id, 'placements', ['mode' => 'automatic'])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! array_key_exists('publisher_platforms', (array) $r['targeting']) && ! array_key_exists('facebook_positions', (array) $r['targeting']) && $r['targeting']['geo_locations']['countries'] === ['SA']);
        $this->assertSame('automatic', $adSet->fresh()->targeting['placement_config']);
    }

    public function test_meta_creates_an_ad_bound_to_one_of_the_project_s_creatives(): void
    {
        $this->configure('meta');
        [, , $adSet] = $this->tree('meta', 'act_5');
        $creative = ExternalCreative::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'meta',
            'external_creative_id' => '2384001', 'name' => 'Hero video', 'format' => 'video', 'status' => 'active',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '7700'])]);

        $this->write('ad_set', $adSet->id, 'create_ad', ['name' => 'Hero — KSA', 'creative_id' => $creative->id])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/act_5/ads') && $r['adset_id'] === $adSet->external_id && $r['creative']['creative_id'] === '2384001' && $r['status'] === 'PAUSED');
        $this->assertSame((string) $creative->id, (string) ExternalAd::query()->where('external_id', '7700')->sole()->creative_id);
    }

    public function test_a_creative_of_another_platform_cannot_be_bound(): void
    {
        $this->configure('meta');
        [, , $adSet] = $this->tree('meta', 'act_5');
        $snap = ExternalCreative::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'snapchat',
            'external_creative_id' => 'sc-1', 'name' => 'Snap', 'format' => 'video', 'status' => 'active',
        ]);
        Http::fake();

        $this->write('ad_set', $adSet->id, 'create_ad', ['name' => 'X', 'creative_id' => $snap->id])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_google_changes_an_ad_s_landing_url_on_the_ad_itself(): void
    {
        $this->configure('google');
        [, , , $ad] = $this->tree('google', '1234567890');
        Http::fake(['googleads.googleapis.com/*' => Http::response(['results' => [['resourceName' => 'x']]])]);

        $this->write('ad', $ad->id, 'destination', ['url' => 'https://store.example/sale'])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'customers/1234567890/ads:mutate')
            && $r['operations'][0]['update']['finalUrls'] === ['https://store.example/sale'] && $r['operations'][0]['updateMask'] === 'final_urls');
        $this->assertSame('https://store.example/sale', $ad->fresh()->destination_url);
    }

    public function test_google_creates_a_search_ad_group_and_needs_its_cpc_bid(): void
    {
        $this->configure('google');
        [, $campaign] = $this->tree('google', '1234567890');
        Http::fake(['googleads.googleapis.com/*' => Http::response(['results' => [['resourceName' => 'customers/1234567890/adGroups/555']]])]);

        $this->write('campaign', $campaign->id, 'create_ad_set', ['name' => 'Brand terms'])->assertStatus(422);
        $this->write('campaign', $campaign->id, 'create_ad_set', ['name' => 'Brand terms', 'bid_amount' => 2.5])->assertOk()->assertJsonPath('data.new_external_id', '555');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'adGroups:mutate') && $r['operations'][0]['create']['type'] === 'SEARCH_STANDARD' && $r['operations'][0]['create']['cpcBidMicros'] === '2500000');
    }

    public function test_snapchat_targeting_replaces_geos_and_demographics_and_keeps_the_rest(): void
    {
        $this->configure('snapchat');
        [, , $adSet] = $this->tree('snapchat', 'sc-acc');
        Http::fake([
            'adsapi.snapchat.com/v1/adsquads/*' => Http::response(['request_status' => 'SUCCESS', 'adsquads' => [['adsquad' => [
                'id' => $adSet->external_id, 'campaign_id' => 'c1', 'name' => 'Set', 'status' => 'ACTIVE',
                'targeting' => ['geos' => [['country_code' => 'sa']], 'interests' => [['category_id' => ['SLC_1']]]],
            ]]]]),
            'adsapi.snapchat.com/v1/campaigns/*' => Http::response(['request_status' => 'SUCCESS', 'adsquads' => [['sub_request_status' => 'SUCCESS']]]),
        ]);

        $this->write('ad_set', $adSet->id, 'targeting', ['countries' => ['SA', 'AE'], 'age_min' => 18, 'age_max' => 34])->assertOk();

        Http::assertSent(function (Request $r): bool {
            $t = $r['adsquads'][0]['targeting'] ?? null;

            return $r->method() === 'PUT' && str_ends_with($r->url(), 'campaigns/c1/adsquads') && is_array($t)
                && $t['geos'] === [['country_code' => 'sa'], ['country_code' => 'ae']] && $t['demographics'][0]['min_age'] === '18'
                && $t['interests'][0]['category_id'] === ['SLC_1'];
        });
    }

    public function test_what_a_platform_keeps_in_the_creative_is_refused_on_the_ad(): void
    {
        $this->configure('meta');
        $this->configure('tiktok');
        [, , , $metaAd] = $this->tree('meta', 'act_5');
        [, , $tiktokSet] = $this->tree('tiktok', '7000001');
        Http::fake();

        $this->write('ad', $metaAd->id, 'destination', ['url' => 'https://x.example'])->assertStatus(409)->assertJsonPath('data.refusal', 'provider_unsupported');
        $this->write('ad_set', $tiktokSet->id, 'targeting', ['countries' => ['SA']])->assertStatus(409)->assertJsonPath('data.refusal', 'not_implemented');
        Http::assertNothingSent();
    }

    // ── The gates ─────────────────────────────────────────────────────────────────────────────

    public function test_an_entity_of_another_campaign_is_not_found(): void
    {
        $this->configure('meta');
        $other = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Other', 'objective' => 'sales', 'status' => 'active']);
        [, $campaign] = $this->tree('meta', 'act_1', unified: $other);
        Http::fake();

        $this->write('campaign', $campaign->id, 'pause')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_an_account_not_selected_for_this_project_is_refused_without_a_call(): void
    {
        $this->configure('meta');
        [$account, $campaign] = $this->tree('meta', 'act_1');
        ProjectIntegrationBinding::query()->where('external_account_id', $account->id)->update(['is_active' => false]);
        Http::fake();

        $this->write('campaign', $campaign->id, 'pause')->assertStatus(409)->assertJsonPath('data.refusal', 'account_not_selected');
        Http::assertNothingSent();
    }

    public function test_the_capability_s_own_permission_is_required_beyond_campaign_management(): void
    {
        $this->configure('meta');
        [, $campaign] = $this->tree('meta', 'act_1');
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'E', 'slug' => 'e']);
        // Everything a campaign manager holds — except the one permission pausing is governed by.
        $role->givePermissionTo(...Permission::query()->where('key', '!=', 'campaigns.pause')->pluck('key')->all());
        $editor = User::create(['name' => 'Editor', 'email' => 'e@w.test', 'password' => 'secret123']);
        $this->grantMembership($editor, $this->tenant);
        $editor->assignRole($role);
        Http::fake();

        $this->actingAs($editor, 'sanctum')->postJson($this->url('provider-writes'), ['level' => 'campaign', 'entity_id' => $campaign->id, 'action' => 'pause'])
            ->assertForbidden()->assertJsonPath('data.refusal', 'no_permission');
        Http::assertNothingSent();
    }

    public function test_a_platform_without_its_app_credentials_is_refused_as_awaiting_them(): void
    {
        [, $campaign] = $this->tree('meta', 'act_1');
        Http::fake();

        $this->write('campaign', $campaign->id, 'pause')->assertStatus(409)->assertJsonPath('data.refusal', 'awaiting_credentials');
        Http::assertNothingSent();
    }

    public function test_demo_rows_exist_on_no_platform(): void
    {
        $this->configure('meta');
        [, $campaign] = $this->tree('meta', 'act_1');
        $adSet = ExternalAdSet::query()->where('external_campaign_id', $campaign->id)->sole();
        $adSet->forceFill(['is_demo' => true])->save();
        Http::fake();

        $this->write('ad_set', $adSet->id, 'pause')->assertStatus(409)->assertJsonPath('data.refusal', 'demo');
    }

    public function test_the_options_name_each_action_s_truthful_state_per_entity(): void
    {
        $this->configure('meta');
        $this->configure('google');
        $this->configure('tiktok');
        [, $meta] = $this->tree('meta', 'act_1');
        [, $google] = $this->tree('google', '1234567890');
        [, , , $tiktokAd] = $this->tree('tiktok', '7000001');

        $body = $this->actingAs($this->owner, 'sanctum')->getJson($this->url('provider-writes'))->assertOk()->json('data.entities');
        $by = collect($body)->keyBy('id');

        $this->assertSame('available', $by[$meta->id]['actions']['archive']);
        $this->assertSame('provider_unsupported', $by[$google->id]['actions']['duplicate']);
        $this->assertSame('not_implemented', $by[$tiktokAd->id]['actions']['rename']);
        $this->assertSame(['daily'], $by[$google->id]['budget_kinds']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function write(string $level, string $entityId, string $action, array $extra = [])
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson($this->url('provider-writes'), ['level' => $level, 'entity_id' => $entityId, 'action' => $action] + $extra);
    }

    private function url(string $tail): string
    {
        return "/api/v1/projects/{$this->project->id}/campaigns/{$this->campaign->id}/{$tail}";
    }

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function account(string $provider, string $externalId, ?string $manager = null): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT-'.$provider, 'RT', Carbon::now()->addDay()),
            connectionName: $provider.' '.uniqid(),
        );
        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => $externalId,
            'parent_external_id' => $manager, 'name' => ucfirst($provider).' account', 'status' => 'active', 'currency' => 'SAR',
        ]);
        ProjectIntegrationBinding::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => $provider, 'purpose' => 'ads', 'is_active' => true,
        ]);

        return $account;
    }

    /** @return array{0: ExternalAccount, 1: ExternalCampaign, 2: ExternalAdSet, 3: ExternalAd} */
    private function tree(string $provider, string $accountExternalId, ?string $manager = null, ?UnifiedCampaign $unified = null): array
    {
        $account = $this->account($provider, $accountExternalId, $manager);
        $unified ??= $this->campaign;
        $campaign = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'unified_campaign_id' => $unified->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'external_id' => (string) random_int(100000, 999999),
            'name' => 'Spring '.$provider, 'status' => 'active', 'currency' => 'SAR',
        ]);
        $adSet = ExternalAdSet::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $campaign->id,
            'unified_campaign_id' => $unified->id, 'provider' => $provider, 'external_id' => (string) random_int(100000, 999999),
            'name' => 'Set '.$provider, 'status' => 'active', 'currency' => 'SAR',
        ]);
        $ad = ExternalAd::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_ad_set_id' => $adSet->id,
            'external_campaign_id' => $campaign->id, 'unified_campaign_id' => $unified->id, 'provider' => $provider,
            'external_id' => (string) random_int(100000, 999999), 'name' => 'Ad '.$provider, 'status' => 'active',
        ]);

        return [$account, $campaign, $adSet, $ad];
    }
}
