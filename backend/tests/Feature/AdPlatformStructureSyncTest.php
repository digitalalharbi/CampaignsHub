<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * STRUCT-001 — the discovery half of the pipeline: campaigns → ad sets → ads → creatives.
 *
 * ## What these tests prove, and what they cannot
 *
 * They prove the adapters read each platform's OWN shape correctly and that the importer places rows
 * against parents it already holds. Every response here is faked, so they are statements about our
 * parsing and never about anybody's API — no install in this repository holds credentials for any of
 * the six, and all six remain **Awaiting Credentials**.
 *
 * The two claims worth writing down separately are the ones a generic model would have got wrong:
 * LinkedIn has no ad-set level and must not be given a synthetic one, and a row naming a parent we
 * have not discovered is skipped and COUNTED rather than attached to a guess.
 */
final class AdPlatformStructureSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Struct', 'slug' => 'struct-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── The full chain, on the platform with all three levels ─────────────────────────────────

    public function test_meta_discovers_campaigns_then_ad_sets_then_ads_and_their_creatives(): void
    {
        $this->configure('meta');
        $account = $this->account('meta');

        Http::fake([
            'graph.facebook.com/*/campaigns*' => Http::response(['data' => [
                ['id' => '120', 'name' => 'Ramadan', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '15000'],
            ]]),
            'graph.facebook.com/*/adsets*' => Http::response(['data' => [
                [
                    'id' => '220', 'campaign_id' => '120', 'name' => 'Riyadh 25-45', 'status' => 'ACTIVE',
                    'optimization_goal' => 'OFFSITE_CONVERSIONS', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                    'daily_budget' => '5000',
                    'targeting' => ['geo_locations' => ['countries' => ['SA']], 'age_min' => 25, 'age_max' => 45],
                ],
            ]]),
            'graph.facebook.com/*/ads*' => Http::response(['data' => [
                [
                    'id' => '320', 'adset_id' => '220', 'campaign_id' => '120', 'name' => 'Video A',
                    'status' => 'ACTIVE', 'effective_status' => 'PENDING_REVIEW',
                    'creative' => ['id' => '420', 'name' => 'Ramadan Hero', 'object_type' => 'VIDEO', 'thumbnail_url' => 'https://scontent.example/t.jpg'],
                ],
            ]]),
        ]);

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('success', $run->status, (string) $run->error);

        $campaign = ExternalCampaign::withoutGlobalScopes()->where('external_id', '120')->firstOrFail();
        $this->assertSame($this->project->id, $campaign->project_id);
        // Meta states budgets in minor units; the edge divides so nothing downstream has to know.
        $this->assertSame('150.0000', $campaign->daily_budget);

        $set = ExternalAdSet::withoutGlobalScopes()->where('external_id', '220')->firstOrFail();
        $this->assertSame($campaign->id, $set->external_campaign_id);
        $this->assertSame('offsite_conversions', $set->optimization_goal);
        $this->assertSame('50.0000', $set->daily_budget);
        $this->assertSame(['SA'], $set->targeting['countries']);
        $this->assertSame('api', $set->source_type);
        $this->assertFalse((bool) $set->is_demo);

        $ad = ExternalAd::withoutGlobalScopes()->where('external_id', '320')->firstOrFail();
        $this->assertSame($set->getKey(), $ad->external_ad_set_id);
        $this->assertSame($campaign->id, $ad->external_campaign_id);
        $this->assertSame('pending', $ad->review_status);

        $creative = ExternalCreative::withoutGlobalScopes()->where('external_creative_id', '420')->firstOrFail();
        $this->assertSame($creative->getKey(), $ad->creative_id);
        $this->assertSame('video', $creative->format);
        $this->assertSame('https://scontent.example/t.jpg', $creative->thumbnail_url);

        // The account now knows when its STRUCTURE was last pulled — a different clock from metrics.
        $this->assertNotNull($account->refresh()->last_structure_synced_at);
        $this->assertNull($account->last_synced_at);
    }

    /**
     * Meta's `effective_status` answers two questions and only one of them is about review.
     *
     * `PAUSED` says somebody turned the ad off. Reading it as a review verdict would print «مرفوض» or
     * «معتمد» beside an ad Meta has said nothing about.
     */
    public function test_a_paused_ad_gets_no_review_verdict_at_all(): void
    {
        $this->configure('meta');
        $account = $this->account('meta');

        Http::fake([
            'graph.facebook.com/*/campaigns*' => Http::response(['data' => [['id' => '120', 'name' => 'C', 'status' => 'PAUSED']]]),
            'graph.facebook.com/*/adsets*' => Http::response(['data' => [['id' => '220', 'campaign_id' => '120', 'name' => 'S', 'status' => 'PAUSED']]]),
            'graph.facebook.com/*/ads*' => Http::response(['data' => [
                ['id' => '320', 'adset_id' => '220', 'campaign_id' => '120', 'name' => 'A', 'status' => 'PAUSED', 'effective_status' => 'ADSET_PAUSED'],
            ]]),
        ]);

        app(AccountStructureSyncer::class)->sync($account);

        $ad = ExternalAd::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($ad->review_status);
        // No creative was named, so none was invented.
        $this->assertNull($ad->creative_id);
        $this->assertSame(0, ExternalCreative::withoutGlobalScopes()->count());
    }

    // ── The platform that does not have the middle level ──────────────────────────────────────

    /**
     * LinkedIn is campaign group → campaign → creative. What this product calls an external campaign
     * IS a LinkedIn campaign, so beneath it there is a creative and nothing in between.
     *
     * The generic model would have made one ad set per campaign to fill the hole — a row LinkedIn
     * never returned, shown to a client as if it had.
     */
    public function test_linkedin_produces_ads_with_no_ad_set_rather_than_a_synthetic_one(): void
    {
        $this->configure('linkedin');
        $account = $this->account('linkedin');

        Http::fake([
            'api.linkedin.com/rest/adAccounts/*/adCampaigns*' => Http::response(['elements' => [
                ['id' => 771, 'name' => 'Leads', 'status' => 'ACTIVE', 'objectiveType' => 'LEAD_GENERATION'],
            ]]),
            'api.linkedin.com/rest/adAccounts/*/creatives*' => Http::response(['elements' => [
                [
                    'id' => 'urn:li:sponsoredCreative:991',
                    'campaign' => 'urn:li:sponsoredCampaign:771',
                    'intendedStatus' => 'ACTIVE',
                    'reviewStatus' => 'APPROVED',
                ],
            ]]),
        ]);

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame(0, ExternalAdSet::withoutGlobalScopes()->count(), 'LinkedIn has no ad-set level to invent');

        $ad = ExternalAd::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($ad->external_ad_set_id);
        $this->assertSame('991', $ad->external_id);
        $this->assertSame('approved', $ad->review_status);
        $this->assertSame(
            ExternalCampaign::withoutGlobalScopes()->where('external_id', '771')->value('id'),
            $ad->external_campaign_id,
        );
    }

    /**
     * The unique index still holds for an ad with no ad set.
     *
     * Postgres treats NULLs as distinct, so the OLD `(external_ad_set_id, external_id)` uniqueness
     * stopped preventing anything the moment the column could be null — every LinkedIn creative would
     * have been re-inserted on every six-hourly sweep. The replacement keys on the campaign.
     */
    public function test_a_second_linkedin_sweep_updates_the_same_ad_instead_of_adding_another(): void
    {
        $this->configure('linkedin');
        $account = $this->account('linkedin');

        Http::fake([
            'api.linkedin.com/rest/adAccounts/*/adCampaigns*' => Http::response(['elements' => [
                ['id' => 771, 'name' => 'Leads', 'status' => 'ACTIVE'],
            ]]),
            'api.linkedin.com/rest/adAccounts/*/creatives*' => Http::response(['elements' => [
                ['id' => 'urn:li:sponsoredCreative:991', 'campaign' => 'urn:li:sponsoredCampaign:771', 'intendedStatus' => 'ACTIVE'],
            ]]),
        ]);

        app(AccountStructureSyncer::class)->sync($account);
        app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame(1, ExternalAd::withoutGlobalScopes()->count());
        $this->assertSame(1, ExternalCampaign::withoutGlobalScopes()->count());
    }

    // ── Placement is resolved, never guessed ──────────────────────────────────────────────────

    /**
     * An ad set naming a campaign we have not discovered is SKIPPED and counted.
     *
     * Attaching it to whichever campaign happened to be first would put one client's ad set under
     * another's campaign. The count is what turns the run `partial`, which is how "the campaign call
     * failed and the ad-set call did not" stays visible.
     */
    public function test_a_row_naming_an_undiscovered_parent_is_skipped_and_makes_the_run_partial(): void
    {
        $this->configure('meta');
        $account = $this->account('meta');

        Http::fake([
            'graph.facebook.com/*/campaigns*' => Http::response(['data' => [['id' => '120', 'name' => 'Known', 'status' => 'ACTIVE']]]),
            'graph.facebook.com/*/adsets*' => Http::response(['data' => [
                ['id' => '220', 'campaign_id' => '999-never-seen', 'name' => 'Orphan', 'status' => 'ACTIVE'],
            ]]),
            'graph.facebook.com/*/ads*' => Http::response(['data' => []]),
        ]);

        $run = app(AccountStructureSyncer::class)->sync($account);

        // INTEG-RUNTIME §8 — rows arrived and one could not be placed: `partial_mapping`.
        $this->assertSame('partial_mapping', $run->status);
        $this->assertStringContainsString('skipped', (string) $run->error);
        $this->assertSame(0, ExternalAdSet::withoutGlobalScopes()->count());
    }

    /** Snapchat names only the squad on an ad; the campaign is read off the squad, which is a fact. */
    public function test_snapchat_places_an_ad_through_its_squad_because_the_ad_does_not_name_a_campaign(): void
    {
        $this->configure('snapchat');
        $account = $this->account('snapchat');

        Http::fake([
            'adsapi.snapchat.com/*/campaigns' => Http::response(['campaigns' => [
                ['campaign' => ['id' => 'cmp-1', 'name' => 'Snap', 'status' => 'ACTIVE']],
            ]]),
            'adsapi.snapchat.com/*/adsquads' => Http::response(['adsquads' => [
                ['adsquad' => ['id' => 'sq-1', 'campaign_id' => 'cmp-1', 'name' => 'Squad', 'status' => 'ACTIVE', 'daily_budget_micro' => 25_000_000]],
            ]]),
            'adsapi.snapchat.com/*/creatives' => Http::response(['creatives' => [
                ['creative' => ['id' => 'crv-1', 'name' => 'Snap Video', 'type' => 'SNAP_AD']],
            ]]),
            'adsapi.snapchat.com/*/ads' => Http::response(['ads' => [
                ['ad' => ['id' => 'ad-1', 'ad_squad_id' => 'sq-1', 'creative_id' => 'crv-1', 'name' => 'Snap Ad', 'status' => 'ACTIVE', 'review_status' => 'APPROVED']],
            ]]),
        ]);

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('success', $run->status, (string) $run->error);

        $ad = ExternalAd::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(
            ExternalCampaign::withoutGlobalScopes()->where('external_id', 'cmp-1')->value('id'),
            $ad->external_campaign_id,
        );
        // Micro-units divided at the edge, as everywhere else Snapchat is read.
        $this->assertSame('25.0000', ExternalAdSet::withoutGlobalScopes()->value('daily_budget'));
        // The creative's real name and format, from the creatives call — not the ad's name reused.
        $this->assertSame('Snap Video', ExternalCreative::withoutGlobalScopes()->value('name'));
    }

    /**
     * TikTok has no creative OBJECT — the media is fields on the ad — so the creative's identity is
     * the video or image it carries, and an ad carrying neither gets no creative row at all.
     */
    public function test_tiktok_builds_a_creative_from_the_media_on_the_ad_and_none_when_there_is_none(): void
    {
        $this->configure('tiktok');
        $account = $this->account('tiktok');

        Http::fake([
            'business-api.tiktok.com/*/campaign/get/*' => Http::response(['code' => 0, 'data' => ['list' => [
                ['campaign_id' => 'c1', 'campaign_name' => 'TT', 'operation_status' => 'ENABLE'],
            ]]]),
            'business-api.tiktok.com/*/adgroup/get/*' => Http::response(['code' => 0, 'data' => ['list' => [
                [
                    'adgroup_id' => 'g1', 'campaign_id' => 'c1', 'adgroup_name' => 'Group', 'operation_status' => 'ENABLE',
                    'optimization_goal' => 'CONVERT', 'budget_mode' => 'BUDGET_MODE_DAY', 'budget' => 300,
                    'location_ids' => ['682'], 'gender' => 'GENDER_FEMALE',
                ],
            ]]]),
            'business-api.tiktok.com/*/ad/get/*' => Http::response(['code' => 0, 'data' => ['list' => [
                ['ad_id' => 'a1', 'adgroup_id' => 'g1', 'campaign_id' => 'c1', 'ad_name' => 'With video', 'operation_status' => 'ENABLE', 'video_id' => 'v-9', 'secondary_status' => 'AD_STATUS_DELIVER_OK'],
                ['ad_id' => 'a2', 'adgroup_id' => 'g1', 'campaign_id' => 'c1', 'ad_name' => 'No media', 'operation_status' => 'ENABLE', 'secondary_status' => 'AD_STATUS_AUDIT'],
            ]]]),
        ]);

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame('convert', ExternalAdSet::withoutGlobalScopes()->value('optimization_goal'));
        $this->assertSame(['682'], ExternalAdSet::withoutGlobalScopes()->value('targeting')['locations']);

        $withVideo = ExternalAd::withoutGlobalScopes()->where('external_id', 'a1')->firstOrFail();
        $this->assertSame('approved', $withVideo->review_status);
        $this->assertSame('video', ExternalCreative::withoutGlobalScopes()->where('external_creative_id', 'v-9')->value('format'));

        $withoutMedia = ExternalAd::withoutGlobalScopes()->where('external_id', 'a2')->firstOrFail();
        $this->assertNull($withoutMedia->creative_id);
        $this->assertSame('pending', $withoutMedia->review_status);
        $this->assertSame(1, ExternalCreative::withoutGlobalScopes()->count());
    }

    /**
     * Google budgets a CAMPAIGN, never an ad group.
     *
     * Copying the campaign's budget down would show the same figure on every ad group beneath it, and
     * an operator reading four ad groups at «100 ر.س / يوم» would conclude the campaign spends four
     * hundred. Google also has no creative object — the ad IS the creative.
     */
    public function test_google_ad_groups_carry_no_budget_and_its_ads_carry_no_separate_creative(): void
    {
        $this->configure('google');
        $account = $this->account('google_ads');

        Http::fake([
            'googleads.googleapis.com/*' => Http::sequence()
                // campaigns
                ->push([['results' => [[
                    'campaign' => ['id' => '55', 'name' => 'Search', 'status' => 'ENABLED'],
                    'campaignBudget' => ['amountMicros' => '100000000'],
                ]]]])
                // ad groups
                ->push([['results' => [[
                    'adGroup' => ['id' => '66', 'name' => 'Brand', 'status' => 'ENABLED', 'cpcBidMicros' => '2000000'],
                    'campaign' => ['id' => '55'],
                ]]]])
                // ads
                ->push([['results' => [[
                    'adGroupAd' => [
                        'ad' => ['id' => '77', 'name' => 'RSA', 'finalUrls' => ['https://example.sa/x']],
                        'status' => 'ENABLED',
                        'policySummary' => ['approvalStatus' => 'DISAPPROVED'],
                    ],
                    'adGroup' => ['id' => '66'],
                    'campaign' => ['id' => '55'],
                ]]]]),
        ]);

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame('100.0000', ExternalCampaign::withoutGlobalScopes()->value('daily_budget'));

        $group = ExternalAdSet::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($group->daily_budget);
        $this->assertNull($group->optimization_goal);
        $this->assertSame('manual_cpc', $group->bid_strategy);

        $ad = ExternalAd::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('rejected', $ad->review_status);
        $this->assertSame('https://example.sa/x', $ad->destination_url);
        $this->assertSame(0, ExternalCreative::withoutGlobalScopes()->count());
    }

    /** A promoted tweet names only its line item, so X's campaign is resolved through the line items. */
    public function test_x_resolves_a_promoted_tweets_campaign_through_its_line_item(): void
    {
        $this->configure('x');
        $account = $this->account('x');

        Http::fake([
            'ads-api.x.com/*/campaigns*' => Http::response(['data' => [
                ['id' => 'cmp-x', 'name' => 'X Launch', 'entity_status' => 'ACTIVE'],
            ]]),
            'ads-api.x.com/*/line_items*' => Http::response(['data' => [
                ['id' => 'li-1', 'campaign_id' => 'cmp-x', 'name' => 'Line', 'entity_status' => 'ACTIVE', 'goal' => 'WEBSITE_CLICKS', 'total_budget_amount_local_micro' => 40_000_000],
            ]]),
            'ads-api.x.com/*/promoted_tweets*' => Http::response(['data' => [
                ['id' => 'pt-1', 'line_item_id' => 'li-1', 'tweet_id' => '1789', 'entity_status' => 'ACTIVE', 'approval_status' => 'ACCEPTED'],
            ]]),
        ]);

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame('40.0000', ExternalAdSet::withoutGlobalScopes()->value('lifetime_budget'));

        $ad = ExternalAd::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(
            ExternalCampaign::withoutGlobalScopes()->where('external_id', 'cmp-x')->value('id'),
            $ad->external_campaign_id,
        );
        $this->assertSame('approved', $ad->review_status);
        $this->assertSame('text', ExternalCreative::withoutGlobalScopes()->value('format'));
    }

    // ── Honest refusals ───────────────────────────────────────────────────────────────────────

    /**
     * An unconfigured platform is not CALLED — and §8 gives that outcome the word `failed`.
     *
     * The point of the test is unchanged and is asserted below: `Http::assertNothingSent()`. Nothing
     * was fabricated and nothing went out; the run says so in words, and the account's error category
     * still separates «add keys» from «the platform had a bad minute».
     */
    public function test_an_unconfigured_platform_calls_nothing_and_records_a_failed_run(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $run = app(AccountStructureSyncer::class)->sync($this->account('tiktok'));

        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->records);
        Http::assertNothingSent();
    }

    /** One failed call does not throw away the three that worked. */
    public function test_a_failing_ad_set_call_still_keeps_the_campaigns_it_discovered(): void
    {
        $this->configure('meta');
        $account = $this->account('meta');

        Http::fake([
            'graph.facebook.com/*/campaigns*' => Http::response(['data' => [['id' => '120', 'name' => 'Kept', 'status' => 'ACTIVE']]]),
            'graph.facebook.com/*/adsets*' => Http::response(['error' => ['message' => 'Application request limit reached']], 400),
            'graph.facebook.com/*/ads*' => Http::response(['data' => []]),
        ]);

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('partial_mapping', $run->status);
        $this->assertStringContainsString('request limit', (string) $run->error);
        $this->assertSame(1, ExternalCampaign::withoutGlobalScopes()->count());
    }

    /** What the platform said is kept beside what we made of it, for structure as well as figures. */
    public function test_the_structure_payloads_are_retained_with_no_window(): void
    {
        $this->configure('meta');
        $account = $this->account('meta');

        Http::fake([
            'graph.facebook.com/*/campaigns*' => Http::response(['data' => [['id' => '120', 'name' => 'C', 'status' => 'ACTIVE']]]),
            'graph.facebook.com/*/adsets*' => Http::response(['data' => []]),
            'graph.facebook.com/*/ads*' => Http::response(['data' => []]),
        ]);

        app(AccountStructureSyncer::class)->sync($account);

        $raw = IntegrationRawPayload::withoutGlobalScopes()->get();
        $this->assertGreaterThan(0, $raw->count());
        $this->assertSame('structure', $raw->first()->resource);
        // Structure is a statement about now, not about a date range.
        $this->assertNull($raw->first()->window_start);
    }

    // ── The sweep ─────────────────────────────────────────────────────────────────────────────

    public function test_the_sweep_queues_one_structure_job_per_connected_account_only(): void
    {
        Queue::fake();

        $connected = $this->account('meta');

        $revoked = $this->account('tiktok');
        ProviderConnection::withoutGlobalScopes()
            ->whereKey($revoked->provider_connection_id)
            ->update(['status' => 'revoked']);

        $this->artisan('integrations:sync-structure')->assertSuccessful();

        Queue::assertPushed(SyncAccountStructureJob::class, 1);
        $this->assertNotNull($connected->fresh());
    }

    /** One in-flight discovery per account — pressing sync during a sweep costs nothing. */
    public function test_two_structure_jobs_for_the_same_account_are_one_job(): void
    {
        $account = $this->account('meta');

        $this->assertSame(
            (new SyncAccountStructureJob((string) $account->id))->uniqueId(),
            (new SyncAccountStructureJob((string) $account->id, ['source' => 'campaign_page']))->uniqueId(),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function account(string $provider): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: $provider,
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => "act_{$provider}",
            'name' => ucfirst($provider),
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        /*
         * ORCH-100 — the account is ASSIGNED to this project.
         *
         * These tests are about the mapping chain (campaigns → ad sets → ads → creatives), and the
         * fixture could previously leave the assignment out because `projectIdFor()` invented one by
         * taking the tenant's oldest project. It no longer does, so a realistic fixture has to
         * include the deliberate act that a real account goes through before it syncs at all.
         */
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $provider,
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);

        return $account;
    }
}
