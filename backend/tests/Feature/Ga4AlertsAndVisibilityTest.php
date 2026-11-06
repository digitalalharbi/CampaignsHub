<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4Breakdowns;
use App\Domains\Integrations\Measurement\Ga4DigestBlock;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Projects\Services\ProjectListSummary;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GA4-STORE-ANALYTICS-001, GA4-PLATFORM-GAP-001.
 *
 * Provider answers are FIXTURES in the shape of the GA4 Data API `runReport` response
 * (`metricHeaders`, `rows[].dimensionValues`, `rows[].metricValues`): no live property exists, so
 * nothing here proves a live round trip. It proves the requests carry Google's documented names and
 * that every figure the store reading states is the arithmetic the rules promise.
 */
final class Ga4AlertsAndVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    private function rule(string $type, array $threshold = [], int $cooldown = 60): AlertRule
    {
        return AlertRule::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'type' => $type, 'name' => $type, 'threshold' => $threshold, 'cooldown_minutes' => $cooldown, 'channels' => ['in_app'], 'create_task' => false, 'severity' => 'warning', 'active' => true]);
    }

    private function sweep(): int
    {
        return app(AlertEvaluator::class)->evaluateAll(Carbon::parse('2026-10-11 09:00:00'));
    }

    /** previous window 09-27..10-03, current 10-04..10-10 (seven days, ending the day before «now») */
    private function trend(ExternalAccount $property, float $before, float $now, string $key = 'transactions'): void
    {
        $this->daily($property, '2026-09-30', [$key => $before, 'sessions' => 1000]);
        $this->daily($property, '2026-10-07', [$key => $now, 'sessions' => 1000]);
    }

    public function test_the_alert_rules_are_off_by_default_and_a_sweep_with_none_raises_nothing(): void
    {
        $property = $this->property($this->project);
        $this->trend($property, 100, 1);

        $this->assertSame(0, AlertRule::query()->count(), 'no migration or seeder creates a GA4 rule');
        $this->assertSame(0, $this->sweep());
        $this->assertSame(0, AlertEvent::query()->count());
    }

    public function test_a_sales_drop_beyond_the_threshold_fires_once_then_is_deduplicated_inside_the_cooldown(): void
    {
        $property = $this->property($this->project);
        $this->trend($property, 100, 40);
        $this->rule('ga4_sales_drop', ['pct' => 30]);

        $this->assertSame(1, $this->sweep());
        $event = AlertEvent::query()->first();
        $this->assertSame('ga4_sales_drop', $event->type);
        $this->assertEquals(-60.0, $event->context['change_pct']);
        $this->assertSame('ga4', $event->context['provider']);
        $this->assertNotEmpty($event->context['title_ar']);

        $this->assertSame(0, $this->sweep(), 'the repeat is suppressed by the engine\'s cooldown and dedup key');
        $this->assertSame(1, AlertEvent::query()->count());
    }

    public function test_a_smaller_move_than_the_threshold_or_a_withheld_figure_is_not_a_breach(): void
    {
        $property = $this->property($this->project);
        $this->trend($property, 100, 90);
        $this->rule('ga4_sales_drop', ['pct' => 30]);
        $this->rule('ga4_bounce_spike', ['pct' => 20]); // no bounce rate measured: nothing to compare

        $this->assertSame(0, $this->sweep());
    }

    public function test_bounce_conversion_and_basket_rules_read_the_same_kpis(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->daily($property, '2026-09-30', ['sessions' => 1000, 'bounce_rate' => 0.40, 'transactions' => 50, 'revenue' => 5000]);
        $this->daily($property, '2026-10-07', ['sessions' => 1000, 'bounce_rate' => 0.60, 'transactions' => 20, 'revenue' => 4000]);
        foreach (['ga4_bounce_spike', 'ga4_cvr_drop', 'ga4_aov_shift'] as $type) {
            $this->rule($type, ['pct' => 20]);
        }

        $this->assertSame(3, $this->sweep());
        $this->assertEqualsCanonicalizing(['ga4_bounce_spike', 'ga4_cvr_drop', 'ga4_aov_shift'], AlertEvent::query()->pluck('type')->all());
    }

    public function test_lost_tracking_fires_only_while_the_platforms_still_report_activity(): void
    {
        $property = $this->property($this->project);
        $this->daily($property, '2026-09-30', ['sessions' => 5000]);
        $this->daily($property, '2026-10-07', ['sessions' => 20]);
        $this->rule('ga4_tracking_loss', ['pct' => 90]);

        $this->assertSame(0, $this->sweep(), 'no platform activity: GA4 falling alone is not «tracking may be broken»');

        $this->campaign('meta', '1', 'x', ['conversions' => 5, 'revenue' => 100, 'spend' => 50]);
        $this->assertSame(1, $this->sweep());
        $this->assertSame('critical', AlertEvent::query()->first()->severity);
    }

    public function test_the_attribution_gap_and_unmatched_spend_rules_use_the_match(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->campaign('meta', '1', 'matched', ['conversions' => 40, 'revenue' => 4000, 'spend' => 100]);
        $this->campaign('snapchat', '2', 'ghost', ['conversions' => 5, 'revenue' => 100, 'spend' => 300]);
        $this->dimRows($property, $this->project, 'campaign', '2026-10-07', [['matched', 'facebook / paid_social', ['sessions' => 100, 'purchases' => 10, 'revenue' => 400]]]);
        $this->rule('ga4_attribution_gap', ['pct' => 50]);
        $this->rule('ga4_unmatched_spend', ['pct' => 40]);

        $this->assertSame(2, $this->sweep());
        $by = AlertEvent::query()->get()->keyBy('type');
        // Snapchat claimed 5 and GA4 credited it nothing (100%); Meta claimed 40 against 10 (75%): the worst platform is named.
        $this->assertSame('snapchat', $by['ga4_attribution_gap']->context['platform']);
        $this->assertEquals(1.0, $by['ga4_attribution_gap']->context['share']);
        $this->assertEquals(0.75, $by['ga4_unmatched_spend']->context['share'], '300 of 400');
        $this->assertStringNotContainsString('spend_usd', json_encode($by['ga4_unmatched_spend']->context), 'a share, never an amount: alerts also reach people who may not read spend');
        $this->assertDoesNotMatchRegularExpression('/\b(300|400|100)\b/', $by['ga4_unmatched_spend']->context['message']);
    }

    public function test_a_rule_never_reads_another_projects_property(): void
    {
        $ws = ClientWorkspace::create(['name' => 'C2', 'slug' => 'c2-'.uniqid(), 'mode' => 'managed']);
        $other = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Other', 'status' => 'active']);
        $theirs = $this->property($other, '999');
        $this->daily($theirs, '2026-09-30', ['transactions' => 100, 'sessions' => 1000], $other);
        $this->daily($theirs, '2026-10-07', ['transactions' => 1, 'sessions' => 1000], $other);
        $this->rule('ga4_sales_drop', ['pct' => 30]); // project-scoped to $this->project, which has no property

        $this->assertSame(0, $this->sweep());
    }

    public function test_the_digest_line_has_visits_orders_and_the_gap_but_no_spend_and_no_campaign(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->campaign('meta', '1', 'secret_campaign_name', ['conversions' => 40, 'revenue' => 4000, 'spend' => 123]);
        $this->daily($property, '2026-10-07', ['sessions' => 900, 'transactions' => 10, 'revenue' => 400]);
        $this->dimRows($property, $this->project, 'campaign', '2026-10-07', [['secret_campaign_name', 'facebook / paid_social', ['sessions' => 900, 'purchases' => 10, 'revenue' => 400]]]);

        $block = app(Ga4DigestBlock::class)->forProject($this->owner, (string) $this->tenant->id, (string) $this->project->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'));
        $line = Ga4DigestBlock::line($block, false);

        $this->assertSame(900.0, $block['sessions']);
        $this->assertSame(10.0, $block['purchases']);
        $this->assertStringContainsString('900 visits', (string) $line);
        $this->assertStringContainsString('platform gap', (string) $line);
        $ar = (string) Ga4DigestBlock::line($block, true);
        $this->assertStringContainsString('زيارة', $ar);
        foreach ([$line, $ar] as $text) {
            $this->assertStringNotContainsString('secret_campaign_name', $text);
            $this->assertStringNotContainsString('123', $text);
            $this->assertStringNotContainsString('spend', strtolower($text));
        }
        $this->assertNull(Ga4DigestBlock::line(null, false));
    }

    public function test_the_digest_ga4_line_is_direction_safe_in_arabic_and_english(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $lang => $dir) {
            $html = (new DailyDigestMail(MailGallery::digest(), $lang, 'M', 'daily'))->render();
            $this->assertSame(1, preg_match('#<div data-block="ga4-line"([^>]*)>(.*?)</div>#s', $html, $m), "$lang: the GA4 line is not in the mail");
            // An explicit direction from the locale, never `auto` (which takes the first strong letter: the Latin «GA4»).
            $this->assertStringContainsString('dir="'.$dir.'"', $m[1], $lang);
            $this->assertStringNotContainsString('dir="auto"', $m[1].$m[2]);
            // Every Latin fragment (counts, dollars, signs) is its own left-to-right island inside the line.
            $this->assertGreaterThanOrEqual(4, substr_count($m[2], 'dir="ltr"'), "$lang: Latin fragments are not isolated");
            foreach (['12,480', '214', '$31,800', '48'] as $figure) {
                $this->assertMatchesRegularExpression('#<span dir="ltr"[^>]*>[^<]*'.preg_quote($figure, '#').'[^<]*</span>#u', $m[2], "$lang: $figure sits outside an island");
            }
        }
    }

    public function test_the_project_list_summary_carries_ga4_health_for_a_project_with_a_property_and_nothing_for_one_without(): void
    {
        $ws = ClientWorkspace::create(['name' => 'C3', 'slug' => 'c3-'.uniqid(), 'mode' => 'managed']);
        $bare = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Bare', 'status' => 'active']);
        $property = $this->property($this->project);
        $property->forceFill(['last_synced_at' => Carbon::now()->subHours(3)])->save();
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->campaign('meta', '1', 'a', ['conversions' => 40, 'revenue' => 4000, 'spend' => 100]);
        DB::table('daily_metrics')->where('project_id', $this->project->id)->update(['metric_date' => Carbon::today()->subDays(3)->toDateString()]);
        $this->dimRows($property, $this->project, 'campaign', Carbon::today()->subDays(3)->toDateString(), [['a', 'facebook / paid_social', ['sessions' => 100, 'purchases' => 10, 'revenue' => 400]]]);

        $out = app(ProjectListSummary::class)->for(collect([$this->project, $bare]));

        $this->assertSame('connected', $out[(string) $this->project->id]['ga4']['state']);
        $this->assertNotNull($out[(string) $this->project->id]['ga4']['last_read_at']);
        $this->assertEquals(0.75, $out[(string) $this->project->id]['ga4']['gap_share'], '(40 claimed - 10 measured) / 40');
        $this->assertNull($out[(string) $bare->id]['ga4']);

        $property->forceFill(['last_synced_at' => Carbon::now()->subDays(5)])->save();
        $this->assertSame('stale', app(ProjectListSummary::class)->for(collect([$this->project]))[(string) $this->project->id]['ga4']['state']);
    }

    public function test_a_member_without_the_store_capability_never_receives_a_sales_figure_from_the_api_or_the_digest(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->campaign('meta', '1', 'a', ['conversions' => 40, 'revenue' => 4000, 'spend' => 100]);
        $this->daily($property, '2026-10-05', ['sessions' => 1000, 'transactions' => 20, 'revenue' => 4000, 'bounce_rate' => 0.4]);
        $this->dimRows($property, $this->project, 'campaign', '2026-10-05', [['a', 'facebook / paid_social', ['sessions' => 1000, 'purchases' => 20, 'revenue' => 4000]]]);
        $this->dimRows($property, $this->project, 'source_medium', '2026-10-05', [['facebook', 'paid_social', ['sessions' => 1000, 'purchases' => 20, 'revenue' => 4000, 'engagement_rate' => 0.5]]]);

        $member = User::create(['name' => 'M', 'email' => 'm-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($member, $this->tenant);
        $narrow = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Narrow', 'slug' => 'narrow']);
        $narrow->givePermissionTo('analytics.view', 'projects.view', 'projects.view.all');
        $member->assignRole($narrow);
        ProjectMembership::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $member->id, 'role' => 'marketing_manager', 'denied' => ['analytics.store.view'], 'status' => 'active']);

        $get = function (string $path) use ($member): array {
            $this->app->forgetScopedInstances();

            return $this->actingAs($member, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/$path".(str_contains($path, '?') ? '&' : '?').'from=2026-10-01&to=2026-10-10')->assertOk()->json('data');
        };
        $sales = ['revenue', 'revenue_usd', 'purchases', 'aov', 'aov_usd', 'conversion_rate', 'transactions', 'roas_ga4'];
        $leaks = function (array $a, string $p = '') use (&$leaks, $sales): array {
            $out = [];
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $out = [...$out, ...$leaks($v, $p.'/'.$k)];
                } elseif (is_numeric($v) && in_array($k, $sales, true)) {
                    $out[] = $p.'/'.$k;
                }
            }

            return $out;
        };

        foreach (['store', 'attribution', 'gap', 'classification?group=source_medium', 'analytics'] as $path) {
            $out = $get($path);
            $this->assertSame([], $leaks($out), "$path carried a sales figure the member may not read");
        }
        $this->assertEquals(1000.0, collect($get('store')['kpis'])->firstWhere('key', 'sessions')['current'], 'the marketing figures are still theirs');

        $this->app->forgetScopedInstances();
        $csv = $this->actingAs($member, 'sanctum')->get("/api/v1/projects/{$this->project->id}/measurement/analytics/table/export?breakdown=source_medium&from=2026-10-01&to=2026-10-10")->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Revenue', explode("\n", $csv)[0]);
        $this->assertStringNotContainsString('Purchases', explode("\n", $csv)[0]);
        $this->assertStringContainsString('Sessions', explode("\n", $csv)[0]);

        $block = app(Ga4DigestBlock::class)->forProject($member, (string) $this->tenant->id, (string) $this->project->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'));
        $this->assertNull($block['purchases']);
        $this->assertNull($block['revenue_usd']);
        $this->assertNull($block['gap']);
        $this->assertSame(1000.0, $block['sessions']);
    }

    public function test_the_hide_parameter_removes_elements_from_the_json_and_the_csv(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->daily($property, '2026-10-05', ['sessions' => 1000, 'transactions' => 20, 'revenue' => 4000]);
        $this->dimRows($property, $this->project, 'source_medium', '2026-10-05', [['facebook', 'paid_social', ['sessions' => 1000, 'purchases' => 20, 'revenue' => 4000]]]);

        $this->app->forgetScopedInstances();
        $all = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/store?from=2026-10-01&to=2026-10-10")->assertOk()->json('data');
        $this->assertContains('revenue', array_column($all['kpis'], 'key'), 'nothing is hidden by default');

        $this->app->forgetScopedInstances();
        $cut = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/store?from=2026-10-01&to=2026-10-10&hide=tile.revenue,tile.aov,col.revenue,evil.key")->assertOk()->json('data');
        $this->assertNotContains('revenue', array_column($cut['kpis'], 'key'));
        $this->assertNotContains('aov', array_column($cut['kpis'], 'key'));
        $this->assertContains('transactions', array_column($cut['kpis'], 'key'));
        $this->assertNull($cut['tables']['sources']['rows'][0]['revenue']);
        $this->assertNotNull($cut['tables']['sources']['rows'][0]['purchases']);

        $this->app->forgetScopedInstances();
        $csv = $this->actingAs($this->owner, 'sanctum')->get("/api/v1/projects/{$this->project->id}/measurement/analytics/table/export?breakdown=source_medium&from=2026-10-01&to=2026-10-10&hide=col.revenue")->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Revenue', explode("\n", $csv)[0]);
        $this->assertStringContainsString('Purchases', explode("\n", $csv)[0]);
    }

    public function test_the_gap_endpoint_decomposes_to_the_gap_and_respects_a_hidden_reason(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->campaign('meta', '1', 'a', ['conversions' => 40, 'revenue' => 4000, 'spend' => 100]);
        $this->campaign('snapchat', '2', 'ghost', ['conversions' => 10, 'revenue' => 100, 'spend' => 50]);
        $this->dimRows($property, $this->project, 'campaign', '2026-10-05', [['a', 'facebook / paid_social', ['sessions' => 500, 'purchases' => 25, 'revenue' => 2500]], ['(direct)', '(direct) / (none)', ['sessions' => 200, 'purchases' => 5, 'revenue' => 500]]]);

        $this->app->forgetScopedInstances();
        $out = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/gap?from=2026-10-01&to=2026-10-10")->assertOk()->json('data');
        $this->assertSame('ready', $out['state']);
        $this->assertEquals(50.0, $out['headline']['claimed']);
        $this->assertEquals(30.0, $out['headline']['measured']);
        $this->assertEquals(20.0, $out['headline']['gap']);
        $this->assertEquals(20.0, $out['check']['sum']);
        $by = array_column($out['reasons'], 'value', 'key');
        $this->assertEquals(['no_trace' => 10.0, 'attribution_rule' => 15.0, 'untagged_channel' => 0.0, 'no_platform_trace' => -5.0], $by);

        $this->app->forgetScopedInstances();
        $cut = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/gap?from=2026-10-01&to=2026-10-10&hide=gap.reason.no_trace")->assertOk()->json('data');
        $this->assertNotContains('no_trace', array_column($cut['reasons'], 'key'));
        $this->assertStringNotContainsString('ghost', json_encode($cut));
    }

    /** @param  array<string, float|int>  $m */
    private function campaign(string $provider, string $externalId, string $name, array $m): ExternalCampaign
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Ads '.$provider);
        $account = ExternalAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'provider' => $provider, 'external_id' => 'ad-'.$provider, 'account_type' => 'ad_account'],
            ['client_workspace_id' => $this->project->client_workspace_id, 'provider_connection_id' => $connection->getKey(), 'name' => 'Ad', 'status' => 'active', 'discovered_at' => Carbon::now()],
        );
        ProjectIntegrationBinding::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id],
            ['client_workspace_id' => $this->project->client_workspace_id, 'provider' => $provider, 'purpose' => 'ads', 'is_active' => true],
        );
        $c = ExternalCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'client_workspace_id' => $this->project->client_workspace_id, 'external_account_id' => $account->id, 'provider' => $provider, 'external_id' => $externalId, 'name' => $name, 'status' => 'active']);
        foreach ([['conversions', $m['conversions']], ['revenue', $m['revenue']], ['spend', $m['spend']]] as [$key, $value]) {
            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'external_campaign_id' => $c->id, 'provider' => $provider,
                'metric_date' => '2026-10-05', 'metric_key' => $key, 'value' => $value, 'project_currency' => 'USD',
            ]);
        }

        return $c;
    }

    private function match(string $query): array
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/attribution?$query")->assertOk()->json('data');
    }

    private function classification(string $query): array
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/classification?$query")->assertOk()->json('data');
    }

    private function property(Project $project, string $external = '111', ?string $currency = 'SAR'): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'ga4', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'GA4 '.$external);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id,
            'provider_connection_id' => $connection->getKey(), 'provider' => 'ga4', 'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE,
            'external_id' => $external, 'name' => 'Store '.$external, 'status' => 'active', 'discovered_at' => Carbon::now(),
            'currency' => $currency, 'timezone' => 'Asia/Riyadh', 'last_synced_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'ga4', 'purpose' => 'analytics', 'is_active' => true,
        ]);

        return $account;
    }

    private function rate(string $from, string $to, string $date, float $rate): void
    {
        CurrencyRate::query()->create(['base_currency' => $from, 'quote_currency' => $to, 'rate' => $rate, 'rate_date' => $date, 'source' => 'manual']);
    }

    /** @param  array<string, float|int>  $values */
    private function daily(ExternalAccount $property, string $date, array $values, ?Project $project = null): void
    {
        foreach ($values as $key => $value) {
            DB::table('measurement_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => ($project ?? $this->project)->id,
                'external_account_id' => $property->id, 'property_id' => (string) $property->external_id, 'metric_date' => $date,
                'metric_key' => $key, 'value' => $value, 'currency' => $key === 'revenue' ? $property->currency : null, 'timezone' => 'Asia/Riyadh',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param  list<array{0: string, 1: string, 2: array<string, float|int>}>  $rows */
    private function dimRows(ExternalAccount $property, Project $project, string $breakdown, string $date, array $rows): void
    {
        foreach ($rows as [$d1, $d2, $metrics]) {
            DB::table('measurement_dimension_rows')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
                'external_account_id' => $property->id, 'property_id' => (string) $property->external_id, 'metric_date' => $date,
                'breakdown' => $breakdown, 'dimension_1' => $d1, 'dimension_2' => $d2, 'metrics' => json_encode($metrics),
                'currency' => array_intersect(Ga4Breakdowns::MONEY, array_keys($metrics)) !== [] ? $property->currency : null, 'timezone' => 'Asia/Riyadh',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
