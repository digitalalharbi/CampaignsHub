<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Mail\OperationalDigestMail;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Notifications\Support\DigestDemo;
use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Notifications\Support\OperationalDigestModel;
use App\Domains\Notifications\Support\PlainFindings;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Services\ReportObservations;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — the Owner's corrections of 2026-11-12 (`docs/OWNER_NOTIFICATIONS_RULES_2026-11-12.md`).
 *
 * Each test names the rule it holds: §1 naming, §2 the period, §3 one mail per active project, §4 performance before linking,
 * §5 each objective from its own campaigns, §6 who may be notified, §7 causes only with evidence.
 */
final class DigestsYesterdayByObjectiveTest extends TestCase
{
    use RefreshDatabase;

    /** 08:00 in Riyadh on Thursday 12 November 2026 — yesterday is Wednesday the 11th. */
    private const NOW_UTC = '2026-11-12 05:00:00';

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-yd-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-yd-'.uniqid()]);
        // The whole permission set: a reader who may see the money gets the operational digest (MEMBER-VISIBILITY-001).
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'Ops', 'email' => 'ops-'.uniqid().'@yd.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function project(string $name, string $status = 'active'): Project
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C'.$name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);

        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => $name, 'status' => $status]);
    }

    private function account(string $provider = 'meta'): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider.uniqid(),
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'acc-'.uniqid(), 'name' => $provider, 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    /**
     * One campaign on an existing ad account, with its figures on given Riyadh dates.
     *
     * @param  array<string, array<string, float>>  $byDate  date → metric → value
     * @param  array<string, mixed>  $over
     */
    private function campaign(Project $project, ExternalAccount $account, string $objective, array $byDate, array $over = []): UnifiedCampaign
    {
        $c = UnifiedCampaign::create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => 'Camp '.uniqid(), 'status' => 'active',
            'objective' => $objective, 'platforms' => [$account->provider], 'total_budget' => 0, 'budget_currency' => 'SAR',
        ], $over));
        if (isset($over['objective_source'])) {
            $c->forceFill(['objective_source' => $over['objective_source']])->save();
        }
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'unified_campaign_id' => $c->id, 'provider' => $account->provider, 'external_id' => 'e-'.uniqid(), 'name' => $c->name, 'status' => 'active',
        ]);
        $rows = [];
        foreach ($byDate as $date => $metrics) {
            foreach ($metrics as $key => $value) {
                $rows[] = new NormalizedMetric(
                    tenantId: (string) $this->tenant->id, projectId: (string) $project->id, provider: (string) $account->provider,
                    externalAccountId: (string) $account->id, externalCampaignId: (string) $external->id, unifiedCampaignId: (string) $c->id,
                    metricDate: Carbon::parse($date), metricKey: $key, value: $value, projectCurrency: 'SAR',
                );
            }
        }
        if ($rows !== []) {
            app(UpsertDailyMetrics::class)->handle($rows);
        }

        return $c;
    }

    /** @param array<string,mixed> $digests */
    private function preference(array $digests): void
    {
        DB::table('notification_preferences')->insert([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->user->id,
            'channels' => json_encode(['email' => true]), 'categories' => json_encode([]), 'digests' => json_encode($digests),
            'timezone' => 'Asia/Riyadh', 'locale' => 'ar', 'digest_hour' => 8, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── §5 — each objective block from ITS campaigns only ────────────────────

    /**
     * One Meta ad account holds a sales, a traffic and an awareness campaign. The importer classified the first two
     * (`objective_source = unset`, the platform's mapped objective) and they used to join the project's inferred objective —
     * so the traffic spend sat inside the sales block and diluted its ROAS, or both landed in «أهداف متعددة».
     */
    public function test_a_mixed_objective_account_is_aggregated_per_real_objective_and_roas_is_sales_only(): void
    {
        $project = $this->project('Mixed');
        $meta = $this->account('meta');
        $this->campaign($project, $meta, 'sales', ['2026-11-11' => ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 80.0, 'conversions' => 4.0, 'purchases' => 4.0, 'revenue' => 400.0]], ['objective_source' => 'unset']);
        $this->campaign($project, $meta, 'traffic', ['2026-11-11' => ['spend' => 200.0, 'impressions' => 20000.0, 'clicks' => 500.0]], ['objective_source' => 'unset']);
        $this->campaign($project, $meta, 'awareness', ['2026-11-11' => ['spend' => 50.0, 'impressions' => 40000.0]], ['objective_source' => 'platform']);

        $day = Carbon::parse('2026-11-11', 'Asia/Riyadh');
        $digest = app(DailyDigest::class)->buildRange($this->user, (string) $this->tenant->id, [(string) $project->id], $day->copy()->startOfDay(), $day->copy()->endOfDay(), 'daily');
        $groups = array_column($digest['projects'][0]['groups'], null, 'objective');

        $this->assertSame(['sales', 'traffic', 'awareness'], array_keys($groups), 'each campaign in its own objective, never the project\'s guess');
        $this->assertEqualsWithDelta(100.0, (float) $groups['sales']['totals']['spend'], 0.001, 'the sales block holds only the sales campaign\'s spend');
        $this->assertEqualsWithDelta(400.0, (float) $groups['sales']['totals']['revenue'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $groups['traffic']['totals']['spend'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $groups['awareness']['totals']['spend'], 0.001);

        $m = OperationalDigestModel::portfolio($digest, 'ar', 'daily');
        $sales = array_column($m['groups'], null, 'objective')['sales'];
        $roas = array_values(array_filter($sales['kpis'], static fn (array $k): bool => $k['key'] === 'roas'))[0];
        $this->assertSame('4.00×', $roas['value'], 'ROAS = sales revenue ÷ SALES spend (400 ÷ 100), never over the whole account (400 ÷ 350)');
        foreach (['traffic', 'awareness'] as $o) {
            $this->assertNotContains('roas', array_column(array_column($m['groups'], null, 'objective')[$o]['kpis'], 'key'));
        }
        $this->assertNotContains('أهداف متعددة', array_column($m['groups'], 'label'));
    }

    /**
     * Review fix — a campaign of another objective that spent only the DAY BEFORE still belongs to its own objective's
     * «previous»: the project is mixed, so no whole-project ROAS movement is computed («ROAS +300%» on 400 ÷ 400).
     */
    public function test_a_campaign_that_spent_only_the_day_before_keeps_its_objective_and_no_blended_roas_is_judged(): void
    {
        $project = $this->project('P');
        $meta = $this->account('meta');
        $this->campaign($project, $meta, 'sales', ['2026-11-10' => ['spend' => 100.0, 'conversions' => 4.0, 'revenue' => 400.0], '2026-11-11' => ['spend' => 100.0, 'conversions' => 4.0, 'revenue' => 400.0]], ['objective_source' => 'platform']);
        $this->campaign($project, $meta, 'traffic', ['2026-11-10' => ['spend' => 300.0, 'clicks' => 900.0]], ['objective_source' => 'platform']);

        $day = Carbon::parse('2026-11-11', 'Asia/Riyadh');
        $digest = app(DailyDigest::class)->buildRange($this->user, (string) $this->tenant->id, [(string) $project->id], $day->copy()->startOfDay(), $day->copy()->endOfDay(), 'daily');
        $block = $digest['projects'][0];
        $groups = array_column($block['groups'], null, 'objective');

        $this->assertSame(['sales', 'traffic'], array_keys($groups));
        $this->assertEqualsWithDelta(100.0, (float) $groups['sales']['previous']['spend'], 0.001, 'the sales group compares with the sales campaign only');
        $this->assertEqualsWithDelta(300.0, (float) $groups['traffic']['previous']['spend'], 0.001);
        $this->assertSame([], array_values(array_filter($block['observations'], static fn (array $n): bool => in_array($n['kind'] ?? '', ['period_comparison', 'rising_cost'], true))), 'no whole-account movement');
        // The traffic group did not deliver yesterday: shown, never judged.
        $m = OperationalDigestModel::project($block + ['project_name' => 'P', 'project_id' => (string) $project->id], 'ar', 1);
        foreach ($m['issues'] as $i) {
            $this->assertNotSame('الزيارات', $i['objective'] ?? null);
        }
    }

    /** A per-project mail opens with the project's TOTAL across its objectives, then the breakdown per objective. */
    public function test_the_project_summary_leads_with_the_total_then_each_objective(): void
    {
        $d = DigestDemo::payload(8, 6);
        $mixed = array_values(array_filter($d['projects'], static fn (array $b): bool => count($b['groups']) > 1))[0];
        $m = OperationalDigestModel::project($mixed, 'ar', 1);
        $this->assertSame(['spend', 'impressions', 'clicks'], array_column($m['kpis'], 'key'), 'the total: what every objective shares, never a blended result');
        $html = (new OperationalDigestMail($m, 'ar', 'م', 'daily', 'project', 'أمس الأربعاء 11 نوفمبر'))->render();
        $this->assertLessThan(strpos($html, 'data-block="group"'), strpos($html, 'data-block="kpis"'), 'total before the objective blocks');
    }

    // ── §6 — only campaigns that are running and delivered ───────────────────

    private function rule(string $type, array $threshold): AlertRule
    {
        return AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => $type, 'name' => $type, 'cooldown_minutes' => 720,
            'channels' => ['in_app'], 'severity' => 'warning', 'active' => true, 'threshold' => $threshold,
        ]);
    }

    /** Seven complete days ending yesterday at ROAS 1, against the seven before at ROAS 10. */
    private function roasDrop(Project $project, ExternalAccount $acc, array $over = [], string $objective = 'sales'): UnifiedCampaign
    {
        $series = [];
        foreach (range(1, 14) as $ago) {
            $date = Carbon::parse('2026-11-12')->subDays($ago)->toDateString();
            $series[$date] = ['spend' => 100.0, 'impressions' => 1000.0, 'conversions' => 2.0, 'revenue' => $ago <= 7 ? 100.0 : 1000.0];
        }

        return $this->campaign($project, $acc, $objective, $series, $over);
    }

    public function test_a_paused_ended_or_completed_campaign_raises_no_performance_alert_and_a_running_one_does(): void
    {
        $project = $this->project('P');
        $acc = $this->account('meta');
        $this->roasDrop($project, $acc, ['status' => 'paused']);
        $this->roasDrop($project, $acc, ['status' => 'completed']);
        $this->roasDrop($project, $acc, ['ends_on' => '2026-11-10']);
        $rule = $this->rule('roas_drop', ['pct' => 25, 'days' => 7]);
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule), 'history, not a decision');

        $running = $this->roasDrop($project, $acc);
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->firstOrFail();
        $this->assertSame((string) $running->id, (string) $event->entity_id);
        $this->assertSame('2026-11-05', $event->context['window_from'], 'seven COMPLETE days ending yesterday (Riyadh)');
        $this->assertSame('2026-11-11', $event->context['window_to']);
    }

    /** An open performance alert on a campaign that then stopped is closed WITHOUT a «back to normal» notice, and never re-raised. */
    public function test_an_open_alert_on_a_campaign_that_stopped_is_closed_silently_and_not_repeated(): void
    {
        $c = $this->roasDrop($this->project('P'), $this->account('meta'));
        $rule = $this->rule('roas_drop', ['pct' => 25, 'days' => 7]);
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $notices = DB::table('app_notifications')->count();

        $c->forceFill(['status' => 'paused'])->save();
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->firstOrFail();
        $this->assertSame('resolved', $event->status);
        $this->assertSame('campaign_not_running', $event->context['closed_reason']);
        $this->assertSame($notices, DB::table('app_notifications')->count(), 'no «recovered» notice about a stopped campaign');

        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC')->addDay());
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule), 'and nothing the next day');
        $this->assertSame($notices, DB::table('app_notifications')->count());
    }

    // ── §7أ — LINKING-ALERT-NO-FALSE-EXPIRY-001 ──────────────────────────────

    private function connection(string $provider, ?string $refresh, string $expiresIn = '+1 hour'): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', $refresh, Carbon::now()->modify($expiresIn)), connectionName: $provider.uniqid(),
        );
    }

    /** Google's access token lasts an hour and renews from the refresh token: «قارب ينتهي» on it was false on a working connection. */
    public function test_an_auto_renewing_connection_with_an_expiring_access_token_raises_nothing(): void
    {
        $c = $this->connection('google', 'RT');
        $c->forceFill(['last_successful_sync_at' => Carbon::now()->subHour()])->save();

        $rule = $this->rule('token_expiry', ['days' => 7]);
        // The false «expiring» card already open from before this fix is closed with no «recovered» notice.
        AlertEvent::create(['tenant_id' => $this->tenant->id, 'rule_id' => $rule->id, 'type' => 'token_expiry', 'entity_type' => get_class($c), 'entity_id' => (string) $c->id,
            'dedup_key' => Str::random(12), 'severity' => 'warning', 'status' => 'open', 'context' => ['provider' => 'google', 'expires_at' => Carbon::now()->addHour()->toIso8601String()], 'last_triggered_at' => now()]);
        $notices = DB::table('app_notifications')->count();

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
        $this->assertSame(0, AlertEvent::where('status', 'open')->count());
        $this->assertSame('renews_itself', AlertEvent::sole()->context['closed_reason']);
        $this->assertSame($notices, DB::table('app_notifications')->count());
    }

    /** A renewal the platform refused (the connection is `error`) is a proven failure: one alert. */
    public function test_a_refused_renewal_raises_one_alert(): void
    {
        $c = $this->connection('google', 'RT');
        $c->forceFill(['status' => 'error', 'last_error' => 'invalid_grant'])->save();

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule('token_expiry', ['days' => 7])));
    }

    /** One connection, three token rules (one per project): ONE card, not three. */
    public function test_one_connection_is_one_alert_however_many_rules_watch_it(): void
    {
        $c = $this->connection('google', 'RT');
        $c->forceFill(['status' => 'error', 'last_error' => 'invalid_grant'])->save();
        $raised = 0;
        foreach (['A', 'B', 'C'] as $name) {
            $rule = $this->rule('token_expiry', ['days' => 7]);
            $rule->forceFill(['project_id' => $this->project($name)->id])->save();
            $raised += app(AlertEvaluator::class)->evaluateRule($rule);
        }

        $this->assertSame(1, $raised);
        $this->assertSame(1, AlertEvent::where('type', 'token_expiry')->where('entity_id', (string) $c->id)->count());
    }

    /** One Google login linked to three projects is three connections — and ONE card when its grant is refused. */
    public function test_one_platform_identity_is_one_card_across_its_project_connections(): void
    {
        $first = $this->connection('google', 'RT');
        $first->forceFill(['status' => 'error', 'last_error' => 'invalid_grant', 'external_owner_id' => 'google-user-1', 'scope' => 'project_only'])->save();
        foreach (['B', 'C'] as $name) {
            $copy = $first->replicate();
            $copy->forceFill(['id' => (string) Str::uuid(), 'connection_name' => 'google '.$name])->save();
        }
        $this->assertSame(3, ProviderConnection::withoutGlobalScopes()->where('provider', 'google')->count());

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule('token_expiry', ['days' => 7])));
    }

    /**
     * A credential that does NOT renew itself, near its expiry, while its data still arrives: no link alert at all
     * (§7أ, the decisive criterion — LINK-HEALTH-YESTERDAY-001). It speaks the morning its yesterday does not arrive.
     */
    public function test_a_non_renewing_credential_near_expiry_raises_nothing_while_yesterday_arrives(): void
    {
        $c = $this->connection('linkedin', null, '+3 days');
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'connection_id' => $c->id, 'provider' => 'linkedin', 'status' => 'success',
            'window_start' => '2026-11-09', 'window_end' => '2026-11-12', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $notices = DB::table('app_notifications')->count();

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('token_expiry', ['days' => 7])));
        $this->assertSame($notices, DB::table('app_notifications')->count(), 'no notice');
    }

    /** ROAS is a sales figure: a traffic campaign with no revenue is never told its return fell. */
    public function test_roas_drop_is_judged_only_on_sales_campaigns(): void
    {
        $this->roasDrop($this->project('P'), $this->account('meta'), [], 'traffic');
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('roas_drop', ['pct' => 25, 'days' => 7])));
    }

    /** Running but with no movement yesterday (spend 0 and impressions 0): no budget alert. */
    public function test_a_running_campaign_with_no_delivery_yesterday_raises_no_budget_alert(): void
    {
        $project = $this->project('P');
        $acc = $this->account('meta');
        // 95% of its budget spent last week, nothing since.
        $this->campaign($project, $acc, 'sales', ['2026-11-05' => ['spend' => 950.0, 'impressions' => 9000.0]], ['total_budget' => 1000]);
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('budget_risk', ['ratio' => 0.9])));

        $this->campaign($project, $acc, 'sales', ['2026-11-11' => ['spend' => 950.0, 'impressions' => 9000.0]], ['total_budget' => 1000]);
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule('budget_risk', ['ratio' => 0.9])), 'delivered yesterday → eligible');
    }

    /** An open alert on a campaign that is no longer running never reaches «يحتاج قرارك الآن»; nor does a connection alert. */
    public function test_open_alerts_of_stopped_campaigns_and_connection_alerts_stay_out_of_the_decisions(): void
    {
        $project = $this->project('P');
        $acc = $this->account('meta');
        $paused = $this->campaign($project, $acc, 'sales', ['2026-11-11' => ['spend' => 10.0, 'impressions' => 100.0]], ['status' => 'paused']);
        $rule = $this->rule('roas_drop', ['pct' => 25, 'days' => 7]);
        foreach ([['roas_drop', UnifiedCampaign::class, (string) $paused->id], ['sync_outage', Project::class, (string) $project->id]] as [$type, $et, $eid]) {
            AlertEvent::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'rule_id' => $rule->id, 'type' => $type, 'entity_type' => $et, 'entity_id' => $eid,
                'dedup_key' => Str::random(12), 'severity' => 'critical', 'priority' => 'P1', 'status' => 'open', 'context' => ['provider' => 'meta'], 'last_triggered_at' => now()]);
        }

        $day = Carbon::parse('2026-11-11', 'Asia/Riyadh');
        $digest = app(DailyDigest::class)->buildRange($this->user, (string) $this->tenant->id, [(string) $project->id], $day->copy()->startOfDay(), $day->copy()->endOfDay(), 'daily');
        $this->assertSame([], $digest['projects'][0]['open_alerts']);
        // Review fix — the connection alert is not dropped: it is in «ملخص الربط».
        $this->assertCount(1, $digest['projects'][0]['open_link_alerts']);
        $m = OperationalDigestModel::project($digest['projects'][0], 'ar', 1);
        $this->assertNotSame([], $m['linking']);
        $this->assertStringContainsString('مزامنة', implode(' ', array_column($m['linking'], 'label')));
    }

    // ── §2 — the period: yesterday, Riyadh, stated ───────────────────────────

    public function test_the_period_label_states_the_day_and_the_dates_in_latin_digits(): void
    {
        $this->assertSame('أمس الأربعاء 11 نوفمبر', DigestSchedule::periodLabel('daily', '2026-11-11', '2026-11-11', true));
        $this->assertSame('Yesterday, Wednesday 11 November', DigestSchedule::periodLabel('daily', '2026-11-11', '2026-11-11', false));
        $this->assertStringContainsString('5–11', DigestSchedule::periodLabel('weekly', '2026-11-05', '2026-11-11', true));
        $this->assertStringContainsString('نوفمبر', DigestSchedule::periodLabel('weekly', '2026-11-05', '2026-11-11', true));
        $this->assertSame('أكتوبر 2026', DigestSchedule::periodLabel('monthly', '2026-10-01', '2026-10-31', true));
        // Review fix — «أمس» only for yesterday: an older day (a stored alert window, a `--date` run) names its day alone.
        $this->assertSame('الأحد 8 نوفمبر', DigestSchedule::periodLabel('daily', '2026-11-08', '2026-11-08', true));
        $this->assertSame('مقارنة باليوم اللي قبله', DigestSchedule::comparedWith('daily', true));
    }

    /** The daily «ملخص المشاريع» at 08:00 Riyadh reports YESTERDAY alone — not seven days — and says so. */
    public function test_the_daily_projects_summary_measures_yesterday_in_riyadh_and_states_it(): void
    {
        Mail::fake();
        config()->set('providers.channels.email', ConfiguredDigestEmailProvider::class);
        config()->set('mail.operational_digests', true);
        foreach (['One', 'Two'] as $name) {
            $this->campaign($this->project($name), $this->account('meta'), 'sales', [
                '2026-11-10' => ['spend' => 50.0, 'impressions' => 1000.0],
                '2026-11-11' => ['spend' => 80.0, 'impressions' => 1500.0],
            ]);
        }
        app(TenantContext::class)->forget();
        $this->preference(['daily' => true]);

        $this->artisan('notifications:send-digests')->assertSuccessful();

        $row = DB::table('digest_sends')->where('user_id', $this->user->id)->where('kind', 'daily')->first();
        $this->assertSame('2026-11-11', substr((string) $row->window_from, 0, 10));
        $this->assertSame('2026-11-11', substr((string) $row->window_to, 0, 10), 'one whole day — yesterday — not seven');
        Mail::assertSent(OperationalDigestMail::class, function (OperationalDigestMail $m): bool {
            $html = $m->render();

            return $m->scope === 'portfolio'
                && $m->periodLabel === 'أمس الأربعاء 11 نوفمبر'
                && str_contains((string) $m->envelope()->subject, 'ملخص المشاريع • أمس')
                && str_contains($html, 'مقارنة باليوم اللي قبله')
                && ! str_contains($html, 'آخر 7 أيام');
        });
    }

    // ── §3 — one mail per ACTIVE project, data or not ────────────────────────

    public function test_every_active_project_gets_its_own_daily_mail_and_one_without_data_says_why(): void
    {
        Mail::fake();
        config()->set('providers.channels.email', ConfiguredDigestEmailProvider::class);
        config()->set('mail.operational_digests', true);
        $this->campaign($this->project('Spending'), $this->account('meta'), 'traffic', ['2026-11-11' => ['spend' => 80.0, 'impressions' => 1500.0, 'clicks' => 40.0]]);
        $this->project('Quiet A');
        $this->project('Quiet B');
        $this->project('Paused', 'paused');
        app(TenantContext::class)->forget();
        $this->preference(['per_project' => true]);

        $this->artisan('notifications:send-digests')->assertSuccessful();

        Mail::assertSent(OperationalDigestMail::class, 3);
        $names = [];
        Mail::assertSent(OperationalDigestMail::class, function (OperationalDigestMail $m) use (&$names): bool {
            $names[] = $m->model['name'];
            $this->assertSame('project', $m->scope, 'a separate mail per project, never a combined one');
            $this->assertSame('أمس الأربعاء 11 نوفمبر', $m->periodLabel);
            $this->assertStringContainsString('ملخص المشروع • أمس', (string) $m->envelope()->subject);
            if ($m->model['name'] !== 'Spending') {
                $this->assertSame('none', $m->model['state']);
                $this->assertStringContainsString('ما فيه منصة إعلانية مربوطة بالمشروع', $m->render(), '«لا بيانات» with the reason the data proves');
            }

            return true;
        });
        sort($names);
        $this->assertSame(['Quiet A', 'Quiet B', 'Spending'], $names, 'the paused project is not reported on');
    }

    // ── §1 — naming ─────────────────────────────────────────────────────────

    public function test_no_user_visible_string_says_mahfaza_and_titles_carry_the_period_word(): void
    {
        $roots = [app_path(), resource_path(), base_path('../frontend/src')];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = (string) $file;
                if (! preg_match('/\.(php|tsx?|json)$/', $path) || preg_match('/\.test\.tsx?$/', $path)) {
                    continue;
                }
                $this->assertStringNotContainsString('محفظة', (string) file_get_contents($path), $path);
                $this->assertStringNotContainsString('المحفظة', (string) file_get_contents($path), $path);
            }
        }
        $d = DigestDemo::payload(6, 4);
        foreach (['daily' => 'أمس', 'weekly' => 'الأسبوع الماضي', 'monthly' => 'الشهر الماضي'] as $kind => $word) {
            $mail = new OperationalDigestMail(OperationalDigestModel::portfolio($d, 'ar', $kind), 'ar', 'م', $kind, 'portfolio');
            $this->assertStringContainsString('ملخص المشاريع • '.$word, (string) $mail->envelope()->subject);
            $this->assertStringContainsString('ملخص المشاريع • '.$word, $mail->render());
        }
    }

    // ── §4 — performance first, linking apart ───────────────────────────────

    public function test_linking_health_is_its_own_section_after_the_decisions_and_never_one_of_them(): void
    {
        $d = DigestDemo::payload(30, 6);
        $m = OperationalDigestModel::portfolio($d, 'ar', 'daily');
        foreach ($m['issues'] as $i) {
            $this->assertNotContains($i['reason'], ['stale', 'sync_failed'], 'a late or failed sync is not a performance decision');
        }
        $this->assertNotSame([], $m['linking'], 'the demo has late and failed sources; they are reported in «ملخص الربط»');
        $html = (new OperationalDigestMail($m, 'ar', 'م', 'daily', 'portfolio', 'أمس الأربعاء 11 نوفمبر'))->render();
        $decide = (int) strpos($html, 'يحتاج قرارك الآن');
        $link = (int) strpos($html, 'data-block="linking"');
        $this->assertGreaterThan(0, $decide);
        $this->assertGreaterThan($decide, $link, '«ملخص الربط» comes after the decisions');
        $this->assertLessThan($decide, (int) strpos($html, 'data-block="kpis"'), 'performance first');
        $this->assertStringNotContainsString('البيانات متأخرة', substr($html, $decide, $link - $decide), 'no stale-data card among the decisions');
    }

    // ── §7 — a cause only with evidence ─────────────────────────────────────

    public function test_a_cause_is_written_only_when_the_budget_data_proves_it(): void
    {
        // Review fix — not comparable (another currency, or refused) proves nothing.
        $this->assertNull(OperationalDigestModel::provenCause(['budget' => 1000, 'spent' => 3750, 'budget_currency' => 'USD', 'spent_currency' => 'SAR', 'refusal' => null, 'spend_withheld' => false], true));
        $this->assertNull(OperationalDigestModel::provenCause(['budget' => 1000, 'spent' => 1200, 'budget_currency' => 'SAR', 'spent_currency' => 'SAR', 'refusal' => 'currency_mismatch', 'spend_withheld' => false], true));
        $this->assertNull(OperationalDigestModel::provenCause(['budget' => 1000, 'spent' => 400], true), 'not proven → no cause');
        $this->assertNull(OperationalDigestModel::provenCause(null, true));
        $this->assertSame('السبب: الإنفاق وصل الميزانية', OperationalDigestModel::provenCause(['budget' => 1000, 'spent' => 1000, 'budget_currency' => 'SAR', 'spent_currency' => 'SAR', 'refusal' => null, 'spend_withheld' => false], true));

        // The findings state the measured fact only — no «منافسة أعلى», «محتوى تعب», «ضعف المحتوى».
        foreach (['rising_cost' => 'cpa', 'falling_rate' => 'ctr'] as $kind => $metric) {
            $say = PlainFindings::say(['kind' => $kind, 'metric' => $metric, 'value' => '12', 'change' => 0.3, 'severity' => 'warning'], true);
            $this->assertDoesNotMatchRegularExpression('/غالبًا|ممكن|ربما|منافسة|محتوى تعب|يخف أثره/u', $say['text']);
        }
        $notes = app(ReportObservations::class)->build(new ReportObjectiveLens('sales'), [
            'currency' => 'SAR', 'kpis' => ['cpa' => 50.0, 'ctr' => 0.01, 'spend' => 1000.0], 'delta' => ['cpa' => 0.5, 'ctr' => -0.5],
            'reported' => [], 'metric_set' => [], 'platforms' => [], 'budget' => [], 'spend_limits' => [], 'freshness' => [],
        ]);
        foreach ($notes as $n) {
            $this->assertDoesNotMatchRegularExpression('/ربما|قد يشير|منافسة|ضعف أداء المحتوى/u', (string) ($n['detail'] ?? ''));
        }
    }

    // ── ALERT-MAIL-DATED-BARS-001 — the approved card, locked ───────────────

    /** @return array{0: string, 1: array<string,mixed>} the rendered project mail and its model */
    private function decisionCards(string $objective, array $was, array $now): array
    {
        $project = $this->project('Card '.$objective);
        $this->campaign($project, $this->account('meta'), $objective, ['2026-11-10' => $was, '2026-11-11' => $now]);
        $day = Carbon::parse('2026-11-11', 'Asia/Riyadh');
        $digest = app(DailyDigest::class)->buildRange($this->user, (string) $this->tenant->id, [(string) $project->id], $day->copy()->startOfDay(), $day->copy()->endOfDay(), 'daily');
        $m = OperationalDigestModel::project($digest['projects'][0], 'ar', 1);

        return [(new OperationalDigestMail($m, 'ar', 'م', 'daily', 'project', 'أمس الأربعاء 11 نوفمبر'))->render(), $m];
    }

    /**
     * The Owner approved this card (2026-11-12): the metric and its % change as the title, before/after bars that name their
     * days («أمس الأربعاء 11 نوفمبر» against «الثلاثاء 10 نوفمبر», never a bare «قبل / الآن»), and numbered action chips.
     */
    public function test_the_decision_card_keeps_the_approved_layout_with_dated_bars(): void
    {
        [$html] = $this->decisionCards('traffic',
            ['spend' => 100.0, 'impressions' => 10000.0, 'clicks' => 300.0, 'link_clicks' => 300.0],
            ['spend' => 100.0, 'impressions' => 10000.0, 'clicks' => 150.0, 'link_clicks' => 150.0]);
        $text = preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($html)));

        $this->assertStringContainsString('data-viz="before_now"', $html);
        $this->assertMatchesRegularExpression('/تكلفة النقرة زادت\s*\S*100%/u', $text, 'the metric and its % change');
        $this->assertStringContainsString('الثلاثاء 10 نوفمبر · ', $text, 'the «before» bar names its day');
        $this->assertStringContainsString('أمس الأربعاء 11 نوفمبر · ', $text, 'the «after» bar names its day');
        preg_match_all('/data-viz="before_now".*?<\/table>\s*<\/td>\s*<\/tr>\s*<\/table>/us', $html, $bars);
        $this->assertNotEmpty($bars[0]);
        foreach ($bars[0] as $bar) {
            $this->assertDoesNotMatchRegularExpression('/>\s*(قبل|الآن) /u', $bar, 'never a bare «قبل / الآن» on a bar');
        }
    }

    /** ROAS cards come only from sales: a traffic campaign whose «return» fell is never judged on it. */
    public function test_a_roas_card_comes_only_from_a_sales_campaign(): void
    {
        $roas = static fn (float $revenue): array => ['spend' => 100.0, 'impressions' => 10000.0, 'clicks' => 200.0, 'link_clicks' => 200.0, 'conversions' => 2.0, 'revenue' => $revenue];
        [$traffic] = $this->decisionCards('traffic', $roas(800.0), $roas(200.0));
        [$sales] = $this->decisionCards('sales', $roas(800.0), $roas(200.0));

        $this->assertStringNotContainsString('العائد على الإنفاق نزل', strip_tags(html_entity_decode($traffic)));
        $this->assertStringContainsString('العائد على الإنفاق', strip_tags(html_entity_decode($sales)));
    }
}
