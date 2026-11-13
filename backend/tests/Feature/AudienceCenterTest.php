<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\MetaConnector;
use App\Domains\Integrations\Providers\ReportsAudienceBreakdowns;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Metrics\Audience\AudienceBreakdownSync;
use App\Domains\Metrics\Audience\AudienceCapabilities;
use App\Domains\Metrics\Audience\AudienceCenter;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Reports\Measurement\AudienceReportSections;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUDIENCE-001 — targeting, paid delivery, site events and organic kept apart; shares from one denominator;
 * nothing summed across platforms; missing is unavailable, never zero; each data type a SHOW/HIDE of its own,
 * enforced on the server for the live link, the saved report and the API; tenant and project isolation.
 *
 * Fixture rows stand in for a synced account; no live provider round trip is proven here.
 */
final class AudienceCenterTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private Project $project;

    private Project $sibling;

    private Report $report;

    private ExternalAccount $meta;

    private ExternalAccount $snap;

    private string $metaSet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $a = ClientWorkspace::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'mode' => 'managed']);
        $b = ClientWorkspace::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $a->id, 'name' => 'P', 'status' => 'active']);
        $this->sibling = Project::create(['client_workspace_id' => $b->id, 'name' => 'Q', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed', 'audience' => 'internal',
            'currency' => 'USD', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'data' => [],
        ]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        $this->meta = $this->account($this->project, 'meta', 'SAR');
        $this->snap = $this->account($this->project, 'snapchat', 'USD');
        $this->metaSet = $this->adSet($this->meta, $this->project, 'meta', 'ext-set-1', 'SECRET_ADSET_NAME', ['cities' => ['Najran'], 'age_min' => 18]);
        $snapSet = $this->adSet($this->snap, $this->project, 'snapchat', 'snap-set-1', 'SNAP_SET', ['countries' => ['sa']]);

        $this->row($this->meta, $this->project, $this->metaSet, 'region', 'Najran Province', '2026-11-12', 300, 250, 6, 30.0, 'SAR');
        $this->row($this->meta, $this->project, $this->metaSet, 'region', 'Makkah Province', '2026-11-12', 100, 90, 2, 10.0, 'SAR');
        $this->row($this->meta, $this->project, $this->metaSet, 'region', 'Najran Province', '2026-11-13', 600, 500, 9, 60.0, 'SAR');
        $this->row($this->snap, $this->project, $snapSet, 'region', 'Najran', '2026-11-12', 1000, 800, 20, 5.0, 'USD');
    }

    // ------------------------------------------------------------------ provider mapping

    public function test_meta_region_rows_keep_the_label_verbatim_and_absent_measures_stay_absent(): void
    {
        $rows = MetaConnector::mapAudienceRows([
            ['adset_id' => '9', 'campaign_id' => '8', 'date_start' => '2026-11-12', 'region' => 'Makkah Province', 'impressions' => '40', 'spend' => '1.5', 'account_currency' => 'sar'],
            ['adset_id' => '9', 'date_start' => '2026-11-12', 'region' => 'Najran Province'],
            ['adset_id' => '', 'date_start' => '2026-11-12', 'region' => 'X'],
        ], 'region');

        $this->assertCount(2, $rows);
        $this->assertSame('Makkah Province', $rows[0]['value'], 'a region is never re-mapped to a city');
        $this->assertSame('SAR', $rows[0]['currency']);
        $this->assertArrayNotHasKey('impressions', $rows[1], 'not reported is absent, never zero');
        $this->assertArrayNotHasKey('spend', $rows[1]);
        $this->assertSame(['region'], (new \ReflectionClass(MetaConnector::class))->newInstanceWithoutConstructor()->audienceDimensions());
    }

    // ------------------------------------------------------------------ ingest

    public function test_the_sync_stores_only_ad_sets_filed_under_the_run_project_and_is_throttled(): void
    {
        DB::table('audience_breakdown_rows')->delete();
        $foreign = $this->adSet($this->account($this->sibling, 'meta', 'SAR', 'ad-meta-sib'), $this->sibling, 'meta', 'sibling-set', 'S', null);
        $connector = new class implements ReportsAudienceBreakdowns
        {
            public int $calls = 0;

            public function audienceDimensions(): array
            {
                return ['region'];
            }

            public function audienceSourceField(string $dimension): string
            {
                return 'test:'.$dimension;
            }

            public function audienceBreakdown(string $adAccountId, string $dimension, string $from, string $to, array $delivering = []): SyncResult
            {
                $this->calls++;

                return SyncResult::of([
                    ['entity_id' => 'ext-set-1', 'date' => '2026-11-12', 'value' => 'Najran Province', 'impressions' => 10.0, 'spend' => 2.0],
                    ['entity_id' => 'sibling-set', 'date' => '2026-11-12', 'value' => 'Riyadh Province', 'impressions' => 99.0],
                    ['entity_id' => 'never-seen', 'date' => '2026-11-12', 'value' => 'X', 'impressions' => 1.0],
                ]);
            }
        };
        $run = MetricSyncRun::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->meta->id, 'provider' => 'meta', 'status' => 'running', 'window_start' => '2026-11-12', 'window_end' => '2026-11-12']);

        $out = app(AudienceBreakdownSync::class)->sync($connector, $this->meta, $run, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-12'));

        $this->assertSame(['state' => 'stored', 'fetched' => 3, 'stored' => 1, 'skipped_unknown_ad_set' => 2], $out['region']);
        $this->assertSame(1, DB::table('audience_breakdown_rows')->count());
        $this->assertSame(0, DB::table('audience_breakdown_rows')->where('external_ad_set_id', $foreign)->count(), 'another project’s ad set is never written');
        $stored = DB::table('audience_breakdown_rows')->first();
        $this->assertSame('SAR', $stored->original_currency);
        $this->assertSame('account', $stored->currency_source);
        $this->assertSame((string) $this->project->id, (string) $stored->project_id);

        $again = app(AudienceBreakdownSync::class)->sync($connector, $this->meta, $run, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-12'));
        $this->assertSame('throttled', $again['state']);
        $this->assertSame(1, $connector->calls, 'a throttled sweep asks the provider nothing');
    }

    // ------------------------------------------------------------------ read model

    public function test_kinds_stay_apart_shares_share_a_denominator_and_platforms_are_never_summed(): void
    {
        $r = app(AudienceCenter::class)->build((string) $this->tenant->id, (string) $this->project->id, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-12'));

        $this->assertSame(['targeting', 'paid_delivery', 'site_events', 'organic'], array_values(array_intersect(array_keys($r), AudienceCenter::KINDS)));
        $this->assertFalse($r['cross_platform']['summed']);
        $this->assertSame(['state' => 'unavailable', 'reason' => 'not_fetched'], $r['organic']);
        $this->assertSame('unavailable', $r['site_events']['state'], 'no GA4 property: unavailable, never zero');

        $groups = collect($r['paid_delivery']['groups'])->keyBy('provider');
        $this->assertCount(2, $groups, 'one group per platform — never one cross-platform total');
        $meta = $groups['meta'];
        $this->assertEquals(400.0, $meta['denominator']);
        $najran = collect($meta['rows'])->firstWhere('value', 'Najran Province');
        $this->assertEquals(['numerator' => 300.0, 'denominator' => 400.0, 'ratio' => 0.75], $najran['share']);
        $this->assertEquals(250.0, $najran['reach'], 'one day: the platform’s unique count is shown');
        $this->assertSame([['currency' => 'SAR', 'amount' => 30.0, 'source' => 'account']], $najran['spend']);
        $this->assertEquals(1000.0, $groups['snapchat']['denominator'], 'snapchat’s impressions are its own denominator');
        $this->assertSame('USD', $groups['snapchat']['rows'][0]['spend'][0]['currency'], 'currencies are never mixed');

        $missing = collect($r['paid_delivery']['unavailable'])->where('provider', 'snapchat')->keyBy('dimension');
        $this->assertSame('api_not_fetched', $missing['age']['state'], 'a dimension not fetched is stated, never a zero');
        $this->assertSame('unavailable_provider', collect($r['paid_delivery']['unavailable'])->where('provider', 'meta')->keyBy('dimension')['city']['state']);

        $target = collect($r['targeting']['items'])->firstWhere('provider', 'meta');
        $this->assertSame('targeting', $target['kind']);
        $this->assertSame(['Najran'], $target['settings']['cities']);
        $this->assertContains('excluded_locations', $target['not_stored'], 'what the summary drops is said, so absence is never read as «not targeted»');
    }

    public function test_reach_is_never_added_across_days_and_a_city_question_never_reads_a_region(): void
    {
        $r = app(AudienceCenter::class)->build((string) $this->tenant->id, (string) $this->project->id, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-13'));
        $meta = collect($r['paid_delivery']['groups'])->firstWhere('provider', 'meta');
        $najran = collect($meta['rows'])->firstWhere('value', 'Najran Province');

        $this->assertEquals(900.0, $najran['impressions']);
        $this->assertNull($najran['reach'], 'daily unique counts are not additive');
        $this->assertSame('reach_not_additive_across_days', $meta['reach_note']);
        $this->assertSame(2, $meta['coverage']['days_with_rows']);

        $parts = $this->sections(true);
        $this->assertNull($parts['audience_cities'], 'a region breakdown never fills the cities part');
        $this->assertIsArray($parts['audience_regions']);
    }

    public function test_money_and_names_are_withheld_for_a_client_reading_and_another_tenant_sees_nothing(): void
    {
        $client = app(AudienceCenter::class)->build((string) $this->tenant->id, (string) $this->project->id, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-12'), true, true);
        $json = (string) json_encode($client);
        $this->assertStringNotContainsString('spend', $json);
        $this->assertStringNotContainsString('SECRET_ADSET_NAME', $json);

        $other = Tenant::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $none = app(AudienceCenter::class)->build((string) $other->id, (string) $this->project->id, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-12'));
        $this->assertSame([], $none['paid_delivery']['groups']);
        $this->assertSame([], $none['targeting']['items']);
        $sib = app(AudienceCenter::class)->build((string) $this->tenant->id, (string) $this->sibling->id, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-12'));
        $this->assertSame([], $sib['paid_delivery']['groups'], 'a sibling project never reads this project’s rows');
    }

    public function test_the_capability_matrix_covers_seven_platforms_and_eight_dimensions(): void
    {
        $all = AudienceCapabilities::all();
        $this->assertSame(['meta', 'snapchat', 'tiktok', 'google', 'x', 'linkedin', 'openai_ads'], array_keys($all));
        foreach ($all as $provider => $cap) {
            $this->assertSame(AudienceCapabilities::DIMENSIONS, array_keys($cap['dimensions']), $provider);
        }
        $stored = collect($all)->flatMap(static fn (array $c, string $p): array => array_map(static fn (string $d): string => "$p.$d", array_keys(array_filter($c['dimensions'], static fn (array $f): bool => $f['delivery'] === 'stored'))))->values()->all();
        $this->assertSame(['meta.region', 'snapchat.region', 'tiktok.region', 'tiktok.gender', 'tiktok.age'], $stored, 'only what is actually fetched claims «stored»');
    }

    // ------------------------------------------------------------------ report surfaces

    public function test_each_audience_type_is_registered_as_its_own_switch_in_the_platforms_view(): void
    {
        $registry = app(ReportSectionRegistry::class);
        foreach (AudienceReportSections::KEYS as $key) {
            $this->assertSame([$key], $registry->get($key)->payloadKeys);
            $this->assertSame('platforms', $registry->get($key)->view);
            $this->assertFalse($registry->get($key)->defaultFor('client'), "$key is OFF on a client report until switched on");
            $this->assertTrue($registry->get($key)->defaultFor('internal'));
        }
        $this->assertFalse($registry->get('audience_spend')->defaultFor('client'));
    }

    public function test_a_live_link_carries_each_part_and_a_hidden_part_takes_its_data_with_it(): void
    {
        // A link is read as a client-facing surface: every audience part, and the spend switch, are OFF until the operator turns them on.
        $this->assertArrayNotHasKey('audience_regions', $this->read($this->link()));
        $this->choose(array_fill_keys(AudienceReportSections::KEYS, true));
        $this->assertStringNotContainsString('"spend"', (string) json_encode($this->read($this->link(['hide_spend' => false]))['audience_regions']));
        $this->choose(array_fill_keys(AudienceReportSections::KEYS, true) + ['audience_spend' => true]);
        $live = $this->read($this->link(['hide_spend' => false]));
        $this->assertContains('audience_regions', $live['report_sections']);
        $this->assertContains('audience_targeting', $live['report_sections']);
        $this->assertIsArray($live['audience_regions']);
        $this->assertStringContainsString('"spend"', (string) json_encode($live['audience_regions']), 'internal report, spend switch on, link shows spend');
        $this->assertStringNotContainsString('"spend"', (string) json_encode($this->read($this->link(['hide_spend' => true]))['audience_regions']), 'the link’s own hide_spend wins');

        $this->choose(['audience_regions' => false, 'audience_spend' => true] + array_fill_keys(AudienceReportSections::KEYS, true));
        $live = $this->read($this->link());
        $this->assertArrayNotHasKey('audience_regions', $live);
        $this->assertStringNotContainsString('Makkah Province', (string) json_encode($live), 'a hidden part leaks nowhere in the JSON');
        $this->assertIsArray($live['audience_targeting']);

        $this->choose(['audience_spend' => false] + array_fill_keys(AudienceReportSections::KEYS, true));
        $live = $this->read($this->link(['hide_spend' => false]));
        $this->assertStringNotContainsString('"spend"', (string) json_encode($live['audience_regions']));

        // The link's own switch narrows without touching the report.
        $this->choose(array_fill_keys(AudienceReportSections::KEYS, true));
        $live = $this->read($this->link(['settings' => ['section_overrides' => ['audience_targeting']]]));
        $this->assertArrayNotHasKey('audience_targeting', $live);
        $this->assertStringNotContainsString('SECRET_ADSET_NAME', (string) json_encode($live));
    }

    public function test_a_client_link_has_no_money_and_no_ad_set_names_and_a_campaign_narrow_link_has_no_audience(): void
    {
        Report::withoutGlobalScopes()->whereKey($this->report->id)->update(['audience' => 'client']);
        $this->choose(['audience_spend' => true]);
        $default = $this->read($this->link());
        foreach (AudienceReportSections::KEYS as $key) {
            $this->assertArrayNotHasKey($key, $default, "$key reached a client link the operator never switched on");
        }
        $this->choose(array_fill_keys(AudienceReportSections::KEYS, true) + ['audience_spend' => true]);
        $live = $this->read($this->link());
        $parts = (string) json_encode(array_intersect_key($live, array_flip(AudienceReportSections::KEYS)), JSON_UNESCAPED_UNICODE);
        $this->assertIsArray($live['audience_regions']);
        $this->assertStringNotContainsString('spend', $parts, 'a client report never carries money, even with the switch on');
        $this->assertStringNotContainsString('SECRET_ADSET_NAME', $parts);

        // A link that names only the Snapchat account never receives Meta's rows.
        $snapOnly = $this->read($this->link([], ['account_ids' => [(string) $this->snap->id]]));
        $this->assertStringNotContainsString('Makkah Province', (string) json_encode($snapOnly, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['snapchat'], array_column($snapOnly['audience_regions']['groups'], 'provider'));

        $narrow = $this->read($this->link([], ['campaign_ids' => [$this->campaignOf($this->metaSet)]]));
        foreach (AudienceReportSections::KEYS as $key) {
            $this->assertNull($narrow[$key] ?? null, "$key reached a campaign-narrow link");
        }
    }

    public function test_the_saved_report_freezes_the_parts_and_never_freezes_a_hidden_one(): void
    {
        $data = $this->snapshot();
        $this->assertIsArray($data['audience_regions']);

        $this->choose(['audience_regions' => false, 'audience_targeting' => false]);
        $data = $this->snapshot();
        $this->assertNull($data['audience_regions'] ?? null);
        $this->assertNull($data['audience_targeting'] ?? null);
    }

    public function test_a_kpi_list_without_spend_takes_audience_money_out_of_a_stored_snapshot(): void
    {
        $parts = $this->sections(true);
        $this->assertStringContainsString('"spend"', (string) json_encode($parts['audience_regions']));
        $cut = AudienceReportSections::withoutMoney($parts);
        $this->assertStringNotContainsString('"spend"', (string) json_encode($cut));
        $this->assertEquals(900.0, collect($cut['audience_regions']['groups'][0]['rows'])->firstWhere('value', 'Najran Province')['impressions'], 'counts stay');
    }

    public function test_reach_is_never_added_across_ad_sets_and_unknown_targeting_keys_never_leave(): void
    {
        $second = $this->adSet($this->meta, $this->project, 'meta', 'ext-set-2', 'SET_TWO', ['cities' => ['Najran'], 'custom_audiences' => ['VIP_LIST_NAME']]);
        $this->row($this->meta, $this->project, $second, 'region', 'Najran Province', '2026-11-12', 50, 40, 1, 5.0, 'SAR');
        $r = app(AudienceCenter::class)->build((string) $this->tenant->id, (string) $this->project->id, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-12'));
        $meta = collect($r['paid_delivery']['groups'])->firstWhere('provider', 'meta');

        $this->assertNull(collect($meta['rows'])->firstWhere('value', 'Najran Province')['reach'], 'two ad sets overlap: no unique count is added');
        $this->assertEquals(90.0, collect($meta['rows'])->firstWhere('value', 'Makkah Province')['reach'], 'one ad set: its own unique count');
        $this->assertSame('reach_not_additive_across_ad_sets', $meta['reach_note']);
        $this->assertStringNotContainsString('VIP_LIST_NAME', (string) json_encode($r), 'only whitelisted targeting keys leave the server');
    }

    public function test_a_link_narrowed_by_objective_gets_no_audience_and_a_campaign_link_is_pinned_to_its_campaign(): void
    {
        $this->choose(array_fill_keys(AudienceReportSections::KEYS, true));
        $objective = $this->read($this->link([], ['objectives' => ['sales']]));
        foreach (AudienceReportSections::KEYS as $key) {
            $this->assertNull($objective[$key] ?? null, "$key reached a link narrowed by objective");
        }

        $unified = (string) Str::uuid();
        DB::table('unified_campaigns')->insert(['id' => $unified, 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'client_workspace_id' => $this->project->client_workspace_id, 'name' => 'U', 'objective' => 'sales', 'objective_source' => 'declared', 'status' => 'active', 'platforms' => json_encode(['snapchat']), 'created_at' => now(), 'updated_at' => now()]);
        $snapSet = (string) DB::table('external_ad_sets')->where('external_id', 'snap-set-1')->value('id');
        DB::table('external_campaigns')->where('id', $this->campaignOf($snapSet))->update(['unified_campaign_id' => $unified]);

        $pinned = $this->read($this->link([], ['campaign_ids' => [$unified], 'dynamic_campaigns' => false]));
        $this->assertSame(['snapchat'], array_column($pinned['audience_regions']['groups'], 'provider'), 'only the named campaign’s rows');
        $this->assertStringNotContainsString('Makkah Province', (string) json_encode($pinned, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_rebound_account_never_rewrites_another_projects_rows(): void
    {
        $connector = new class implements ReportsAudienceBreakdowns
        {
            public function audienceDimensions(): array
            {
                return ['region'];
            }

            public function audienceSourceField(string $dimension): string
            {
                return 'test:'.$dimension;
            }

            public function audienceBreakdown(string $adAccountId, string $dimension, string $from, string $to, array $delivering = []): SyncResult
            {
                return SyncResult::of([['entity_id' => 'sib-set', 'date' => '2026-11-12', 'value' => 'Najran Province', 'impressions' => 7.0]]);
            }
        };
        // The same account, now filed under the sibling project too.
        $this->adSet($this->meta, $this->sibling, 'meta', 'sib-set', 'S', null);
        ProjectIntegrationBinding::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->sibling->client_workspace_id, 'project_id' => $this->sibling->id, 'external_account_id' => $this->meta->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true]);
        $run = MetricSyncRun::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->sibling->id, 'external_account_id' => $this->meta->id, 'provider' => 'meta', 'status' => 'running', 'window_start' => '2026-11-12', 'window_end' => '2026-11-12']);
        $before = DB::table('audience_breakdown_rows')->where('project_id', $this->project->id)->count();

        app(AudienceBreakdownSync::class)->sync($connector, $this->meta, $run, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-12'));

        $this->assertSame($before, DB::table('audience_breakdown_rows')->where('project_id', $this->project->id)->count());
        $this->assertSame(1, DB::table('audience_breakdown_rows')->where('project_id', $this->sibling->id)->count());
    }

    // ------------------------------------------------------------------ API

    public function test_the_api_answers_a_member_strips_money_without_money_view_and_refuses_another_tenant(): void
    {
        $this->seed(PermissionSeeder::class);
        $peer = $this->member($this->tenant, ProjectRole::MEDIA_BUYER);
        $withheld = $this->member($this->tenant, ProjectRole::MEDIA_BUYER, [ProjectCapability::MONEY_VIEW]);

        $ok = $this->actingAs($peer, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/audience?from=2026-11-12&to=2026-11-12")->assertOk();
        $this->assertSame('ready', $ok->json('data.paid_delivery.state'));
        $this->assertStringContainsString('"spend"', $ok->getContent());

        $cut = $this->actingAs($withheld, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/audience?from=2026-11-12&to=2026-11-12")->assertOk();
        $this->assertStringNotContainsString('"spend"', $cut->getContent());

        $otherTenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        $stranger = $this->member($otherTenant, ProjectRole::MEDIA_BUYER, [], false);
        $res = $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/audience?from=2026-11-12&to=2026-11-12");
        $this->assertContains($res->status(), [403, 404]);
        $this->assertStringNotContainsString('Najran', $res->getContent());
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, array<string, mixed>|null> */
    private function sections(bool $internal): array
    {
        return app(AudienceReportSections::class)->build((string) $this->tenant->id, (string) $this->project->id, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-13'), ! $internal, static fn (string $k): bool => true);
    }

    /** @param list<string> $denied */
    private function member(Tenant $tenant, string $projectRole, array $denied = [], bool $onProject = true): User
    {
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Staff', 'slug' => 'staff-'.uniqid()]);
        $role->givePermissionTo(...['projects.view', 'campaigns.view', 'analytics.view', 'reports.view']);
        $user = User::create(['name' => 'M', 'email' => 'm-'.uniqid().'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);
        if ($onProject) {
            ProjectMembership::create([
                'tenant_id' => $tenant->id, 'project_id' => $this->project->id, 'user_id' => $user->id,
                'role' => $projectRole, 'status' => 'active', 'denied' => $denied,
            ]);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $data = app(ReportGenerator::class)->generate($this->report->fresh());
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $data;
    }

    /** @param array<string, bool> $sections */
    private function choose(array $sections): void
    {
        Report::withoutGlobalScopes()->whereKey($this->report->id)->update(['section_settings' => json_encode(['sections' => $sections])]);
    }

    /**
     * @param  array<string, mixed>  $opts
     * @param  array<string, mixed>  $scope
     */
    private function link(array $opts = [], array $scope = []): string
    {
        [, $raw] = app(ShareService::class)->create($this->report->fresh(), $opts + ['scope' => $scope + [
            'project_id' => (string) $this->project->id, 'campaign_ids' => [], 'account_ids' => [(string) $this->meta->id, (string) $this->snap->id], 'providers' => [],
            'dynamic_campaigns' => true, 'earliest' => '2026-11-01', 'latest' => '2026-11-30',
        ]], null);

        return $raw;
    }

    /** @return array<string, mixed> */
    private function read(string $raw): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-11-12&to=2026-11-12")->assertOk()->json('data');
    }

    private function account(Project $project, string $provider, string $currency, ?string $external = null): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Ads '.$provider.uniqid());
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => $external ?? 'ad-'.$provider, 'name' => 'Ad', 'status' => 'active',
            'currency' => $currency, 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return $account;
    }

    /** @param array<string, mixed>|null $targeting */
    private function adSet(ExternalAccount $account, Project $project, string $provider, string $external, string $name, ?array $targeting): string
    {
        $base = ['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()];
        $campaign = (string) Str::uuid();
        DB::table('external_campaigns')->insert($base + [
            'id' => $campaign, 'client_workspace_id' => $project->client_workspace_id, 'external_account_id' => $account->id, 'provider' => $provider,
            'external_id' => 'c-'.$external, 'name' => 'CAMP_'.$name, 'status' => 'active',
        ]);
        $id = (string) Str::uuid();
        DB::table('external_ad_sets')->insert($base + [
            'id' => $id, 'external_campaign_id' => $campaign, 'provider' => $provider, 'external_id' => $external, 'name' => $name,
            'status' => 'active', 'targeting' => $targeting === null ? null : json_encode($targeting),
        ]);

        return $id;
    }

    private function campaignOf(string $adSet): string
    {
        return (string) DB::table('external_ad_sets')->where('id', $adSet)->value('external_campaign_id');
    }

    private function row(ExternalAccount $account, Project $project, string $adSet, string $dimension, string $value, string $date, float $imp, float $reach, float $clicks, float $spend, string $currency): void
    {
        DB::table('audience_breakdown_rows')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => $account->provider, 'dimension' => $dimension, 'value' => $value, 'source_field' => $account->provider.':test',
            'external_campaign_id' => $this->campaignOf($adSet), 'external_ad_set_id' => $adSet,
            'external_entity_id' => (string) DB::table('external_ad_sets')->where('id', $adSet)->value('external_id'), 'metric_date' => $date,
            'impressions' => $imp, 'reach' => $reach, 'clicks' => $clicks, 'spend_original' => $spend, 'original_currency' => $currency,
            'currency_source' => 'account', 'synced_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
