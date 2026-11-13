<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Support\DigestStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ALERT-DELIVERING-YESTERDAY-001 — «كرم النخيل» (2026-11-13): «صرف بدون نتائج» kept arriving for Snapchat campaigns paused
 * on the platform since 10 Nov. Production read-only facts: our rows said `active` (adopted, never refreshed) while
 * `external_campaigns.status` said `paused`; their last spend was 9 Nov; the rule's «last 3 days» from the server's clock
 * still reached 9 Nov, so the alert was refreshed through 12 Nov and then closed with a «تعافى» nobody measured.
 *
 * The Owner's rules (§2, §6): the daily judgement measures YESTERDAY on the client's clock; a campaign qualifies only if it
 * ran yesterday (spend or impressions) and is running per the latest platform data; spend from before a pause is never
 * counted; an alert on a campaign that no longer qualifies closes silently.
 *
 * The clock is pinned at 08:15 Riyadh on 13 Nov (05:15 UTC): «yesterday» is 12 Nov.
 */
final class NoResultsDeliveringYesterdayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 05:15:00', 'UTC'));
        $this->tenant = Tenant::create(['name' => 'Karam', 'slug' => 'karam', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rule(int $days = 3): AlertRule
    {
        return AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => 'no_results', 'name' => 'No results', 'threshold' => ['days' => $days],
            'cooldown_minutes' => 720, 'channels' => ['in_app', 'email'], 'severity' => 'warning', 'active' => true,
        ]);
    }

    private function campaign(string $status = 'active', array $meta = []): UnifiedCampaign
    {
        $c = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Camp '.uniqid(),
            'objective' => 'conversions', 'status' => $status, 'budget_currency' => 'SAR', 'meta' => $meta ?: null,
        ]);
        // Adopted long before the pause, like Karam's (updated 5 Nov).
        DB::table('unified_campaigns')->where('id', $c->id)->update(['created_at' => '2026-11-05 10:00:00+00', 'updated_at' => '2026-11-05 10:00:00+00']);

        return $c->fresh();
    }

    /** The platform's campaign behind ours, with its normalised status and last sync. */
    private function platform(UnifiedCampaign $c, string $status, string $syncedAt = '2026-11-13 06:57:00+00', string $provider = 'snapchat'): string
    {
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::create(['tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected']);
        $account = (string) Str::uuid();
        DB::table('external_accounts')->insert([
            'id' => $account, 'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'acct-'.uniqid(), 'name' => 'A', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('external_campaigns')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'unified_campaign_id' => $c->id, 'external_account_id' => $account, 'provider' => $provider,
            'external_id' => 'ext-'.uniqid(), 'name' => $c->name, 'status' => $status,
            'last_synced_at' => $syncedAt, 'created_at' => '2026-11-05 10:00:00+00', 'updated_at' => $syncedAt,
        ]);

        return $account;
    }

    /** A metrics sync of the account that succeeded over these days. */
    private function synced(string $account, string $from, string $to): void
    {
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account, 'provider' => 'snapchat', 'status' => 'success',
            'window_start' => $from, 'window_end' => $to, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** One day's delivery, on a Riyadh calendar date. */
    private function day(UnifiedCampaign $c, string $date, float $spend, float $conversions = 0, float $impressions = 1000, string $provider = 'snapchat'): void
    {
        foreach (['spend' => $spend, 'impressions' => $impressions, 'conversions' => $conversions] as $key => $value) {
            DB::table('daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $c->id, 'provider' => $provider, 'metric_key' => $key,
                'metric_date' => $date, 'value' => $value, 'project_currency' => 'SAR',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @return list<string> */
    private function alerted(AlertRule $rule): array
    {
        return AlertEvent::where('rule_id', $rule->id)->where('status', 'open')->pluck('entity_id')->map(static fn ($id): string => (string) $id)->all();
    }

    /**
     * Karam's evening sweep: 21:15 Riyadh on 12 Nov. Paused since 10 Nov, its spend on 9 Nov was still inside «the last 3 days»
     * counted from the server's clock (9 Nov 18:15 UTC), and the alert was refreshed. Yesterday (11 Nov) it delivered nothing: no alert.
     */
    public function test_a_campaign_paused_three_days_ago_with_spend_earlier_in_the_window_is_not_alerted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 18:15:00', 'UTC'));
        $c = $this->campaign();
        $this->day($c, '2026-11-08', 40);
        $this->day($c, '2026-11-09', 23);
        // 10–12 Nov: nothing delivered.
        $this->day($c, '2026-11-12', 0, impressions: 0);

        $rule = $this->rule();
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
        $this->assertSame([], $this->alerted($rule));
    }

    /** Karam's two Snapchat campaigns: ours says active, the platform says paused — even with spend yesterday, no alert. */
    public function test_stored_active_but_paused_on_the_platform_is_not_alerted(): void
    {
        $c = $this->campaign(meta: ['adopted_from' => 'sync']);
        $this->platform($c, 'paused');
        $this->day($c, '2026-11-11', 30);
        $this->day($c, '2026-11-12', 12);

        $rule = $this->rule();
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
        $this->assertSame([], $this->alerted($rule));
    }

    /** The control: running on the platform and delivering yesterday with 0 of its result IS «صرف بدون نتائج». */
    public function test_a_campaign_delivering_yesterday_with_no_result_is_alerted(): void
    {
        $c = $this->campaign(meta: ['adopted_from' => 'sync']);
        $this->platform($c, 'active');
        $this->day($c, '2026-11-10', 30);
        $this->day($c, '2026-11-11', 25);
        $this->day($c, '2026-11-12', 27);

        $rule = $this->rule();
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->firstOrFail();
        $this->assertSame((string) $c->id, (string) $event->entity_id);
        $this->assertEqualsWithDelta(82.0, (float) $event->context['spend'], 0.001);
        $this->assertSame(['2026-11-10', '2026-11-12', 3], [$event->context['from'], $event->context['to'], $event->context['days']]);
        // §10أ — what was proven travels with the alert; what we do not fetch says so, never a guess.
        $checks = $event->context['checks'];
        $this->assertSame('active', $checks['running']['status']);
        $this->assertSame(['active'], $checks['running']['platform']);
        $this->assertSame('2026-11-10', $checks['active_since']);
        $this->assertSame(['not_available', 'not_available', 'not_available'], [$checks['learning_phase'], $checks['setup'], $checks['payment']]);
        $this->assertSame('active', $event->context['campaign_status']);
    }

    /**
     * §10أ check 2 — a run too small to judge is not «بدون نتائج»: started two days ago and spent 30 (a sales family's minimum is
     * 50). A rule may set its own minimum; at 20 the same campaign is judged.
     */
    public function test_a_campaign_below_the_minimum_sample_is_not_judged_and_a_rule_can_set_its_own(): void
    {
        $c = $this->campaign();
        $this->day($c, '2026-11-11', 12);
        $this->day($c, '2026-11-12', 18);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));

        $own = AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => 'no_results', 'name' => 'Own minimum', 'threshold' => ['days' => 3, 'min_spend' => 20],
            'cooldown_minutes' => 720, 'channels' => ['in_app'], 'severity' => 'warning', 'active' => true,
        ]);
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($own));
    }

    /** §10أ check 3 — the platform's end date passed (ours had none): ended, not «weak». */
    public function test_a_campaign_past_its_platform_end_date_is_not_judged(): void
    {
        $c = $this->campaign(meta: ['adopted_from' => 'sync']);
        $this->platform($c, 'active');
        DB::table('external_campaigns')->where('unified_campaign_id', $c->id)->update(['ends_at' => '2026-11-12 20:59:00+00']); // 23:59 Riyadh, 12 Nov
        $this->day($c, '2026-11-11', 80);
        $this->day($c, '2026-11-12', 80);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }

    /**
     * §10أ check 3 — the lifetime budget the platform states is spent: the action is a renewal, not «بدون نتائج». A budget in another
     * currency than the stored spend cannot be compared, and is recorded as not available rather than guessed.
     */
    public function test_a_spent_lifetime_budget_is_a_renewal_not_a_performance_verdict(): void
    {
        $spent = $this->campaign(meta: ['adopted_from' => 'sync']);
        $this->platform($spent, 'active');
        DB::table('external_campaigns')->where('unified_campaign_id', $spent->id)->update(['lifetime_budget' => 150, 'currency' => 'SAR']);
        $other = $this->campaign(meta: ['adopted_from' => 'sync']);
        $this->platform($other, 'active');
        DB::table('external_campaigns')->where('unified_campaign_id', $other->id)->update(['lifetime_budget' => 150, 'currency' => 'USD']);
        foreach ([$spent, $other] as $c) {
            $this->day($c, '2026-11-11', 80);
            $this->day($c, '2026-11-12', 80);
        }

        $rule = $this->rule();
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->firstOrFail();
        $this->assertSame((string) $other->id, (string) $event->entity_id);
        $this->assertSame('not_available', $event->context['checks']['lifetime_budget']);
    }

    /**
     * Restarted on 12 Nov after a pause on 11 Nov: judged on 12 Nov alone. Its orders on 10 Nov are before the restart and do
     * not hide yesterday's zero; and the spend quoted is yesterday's, not the window's.
     */
    public function test_a_restarted_campaign_is_judged_only_on_spend_since_the_restart(): void
    {
        $c = $this->campaign();
        $this->day($c, '2026-11-10', 100, conversions: 6);
        // 11 Nov: paused.
        $this->day($c, '2026-11-12', 60);

        $rule = $this->rule();
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->firstOrFail();
        $this->assertEqualsWithDelta(60.0, (float) $event->context['spend'], 0.001);
        $this->assertSame('2026-11-12', $event->context['active_since']);
        $this->assertSame(['2026-11-12', '2026-11-12', 1], [$event->context['from'], $event->context['to'], $event->context['days']]);
    }

    /** Spend from today (a partial day) is not yesterday's: a campaign that resumed this morning is not judged yet. */
    public function test_today_is_not_part_of_the_judgement(): void
    {
        $c = $this->campaign();
        $this->day($c, '2026-11-13', 50);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }

    /** The alert the old rule left open on a campaign paused on its platform closes with its reason — no «تعافى», no mail. */
    public function test_an_open_alert_on_a_paused_campaign_closes_silently(): void
    {
        $rule = $this->rule();
        $open = function (UnifiedCampaign $c) use ($rule): AlertEvent {
            return AlertEvent::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'rule_id' => $rule->id, 'type' => 'no_results',
                'entity_type' => UnifiedCampaign::class, 'entity_id' => $c->id,
                'dedup_key' => hash('sha256', implode('|', [$rule->id, UnifiedCampaign::class, $c->id])),
                'status' => 'open', 'severity' => 'warning',
                'context' => ['spend' => 23.0, 'conversions' => 0.0, 'days' => 3, 'result_key' => 'conversions', 'title' => 'Spending with no results', 'message' => 'x'],
                'last_triggered_at' => Carbon::now()->subHours(8),
            ]);
        };

        $pausedOnPlatform = $this->campaign(meta: ['adopted_from' => 'sync']);
        $this->platform($pausedOnPlatform, 'paused');
        $this->day($pausedOnPlatform, '2026-11-09', 23);
        $stoppedDelivering = $this->campaign();
        $this->day($stoppedDelivering, '2026-11-09', 69);
        $a = $open($pausedOnPlatform);
        $b = $open($stoppedDelivering);

        $evaluator = app(AlertEvaluator::class);
        $this->assertSame(0, $evaluator->evaluateRule($rule));

        $this->assertSame('resolved', $a->fresh()->status);
        $this->assertSame('campaign_inactive', $a->fresh()->context['closed_reason']);
        $this->assertSame('resolved', $b->fresh()->status);
        $this->assertSame('not_delivering', $b->fresh()->context['closed_reason']);
        foreach ([$a, $b] as $e) {
            $this->assertArrayNotHasKey('recovered_at', $e->fresh()->context);
        }
        $this->assertSame(0, AppNotification::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame([], (new \ReflectionProperty($evaluator, 'outbox'))->getValue($evaluator));
    }

    /**
     * Review (P2) — a late or failed sync is not a pause. A running campaign with no figures for yesterday, whose account's metrics
     * sync has not covered yesterday, gets no verdict: its open alert stays open, neither closed nor «تعافى». Once a successful
     * sync covered yesterday and it still delivered nothing, it closes silently as not delivering.
     */
    public function test_missing_figures_from_an_unfinished_sync_hold_the_alert_rather_than_close_it(): void
    {
        $rule = $this->rule();
        $c = $this->campaign(meta: ['adopted_from' => 'sync']);
        $account = $this->platform($c, 'active');
        $this->synced($account, '2026-11-08', '2026-11-11');
        $this->day($c, '2026-11-10', 60);
        $this->day($c, '2026-11-11', 60);
        $event = AlertEvent::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'rule_id' => $rule->id, 'type' => 'no_results',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => $c->id,
            'dedup_key' => hash('sha256', implode('|', [$rule->id, UnifiedCampaign::class, $c->id])),
            'status' => 'open', 'severity' => 'warning',
            'context' => ['spend' => 120.0, 'conversions' => 0.0, 'days' => 2, 'result_key' => 'conversions', 'title' => 'Spending with no results', 'message' => 'x'],
            'last_triggered_at' => Carbon::now()->subHours(2),
        ]);

        app(AlertEvaluator::class)->evaluateRule($rule);
        $this->assertSame('open', $event->fresh()->status);
        $this->assertSame(0, AppNotification::where('tenant_id', $this->tenant->id)->count());

        $this->synced($account, '2026-11-12', '2026-11-12');
        app(AlertEvaluator::class)->evaluateRule($rule);
        $this->assertSame('resolved', $event->fresh()->status);
        $this->assertSame('not_delivering', $event->fresh()->context['closed_reason']);
        $this->assertSame(0, AppNotification::where('tenant_id', $this->tenant->id)->count());
    }

    /** Review (P3) — an anomaly alert on a campaign now paused closes silently too; it did not «recover». */
    public function test_an_anomaly_alert_on_a_paused_campaign_closes_silently(): void
    {
        $c = $this->campaign('paused');
        $rule = AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => 'metric_anomaly', 'name' => 'Anomaly', 'threshold' => ['days' => 21],
            'cooldown_minutes' => 720, 'channels' => ['in_app'], 'severity' => 'warning', 'active' => true,
        ]);
        $event = AlertEvent::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'rule_id' => $rule->id, 'type' => 'metric_anomaly',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => $c->id,
            'dedup_key' => hash('sha256', implode('|', [$rule->id, UnifiedCampaign::class, $c->id])),
            'status' => 'open', 'severity' => 'warning', 'context' => ['points' => [], 'title' => 'Unusual day', 'message' => 'x'],
            'last_triggered_at' => Carbon::now()->subHours(2),
        ]);

        app(AlertEvaluator::class)->evaluateRule($rule);

        $this->assertSame('resolved', $event->fresh()->status);
        $this->assertSame('campaign_inactive', $event->fresh()->context['closed_reason']);
        $this->assertSame(0, AppNotification::where('tenant_id', $this->tenant->id)->count());
    }

    /** A status a person set after the last sync stays theirs: paused by hand here, though the platform still said active. */
    public function test_a_status_set_after_the_last_sync_wins_over_the_platform(): void
    {
        $c = $this->campaign('paused');
        DB::table('unified_campaigns')->where('id', $c->id)->update(['updated_at' => '2026-11-13 05:00:00+00']);
        $this->platform($c, 'active', '2026-11-13 02:00:00+00');
        $this->day($c, '2026-11-12', 40);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }

    /** The same eligibility for ROAS: a campaign paused on its platform is not asked about, and its open alert closes silently. */
    public function test_roas_drop_ignores_a_campaign_paused_on_the_platform(): void
    {
        $c = $this->campaign(meta: ['adopted_from' => 'sync']);
        $this->platform($c, 'paused');
        foreach (['2026-11-01' => 400, '2026-11-02' => 400, '2026-11-10' => 50, '2026-11-12' => 50] as $date => $revenue) {
            $this->day($c, $date, 100);
            DB::table('daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $c->id, 'provider' => 'snapchat', 'metric_key' => 'revenue',
                'metric_date' => $date, 'value' => $revenue, 'project_currency' => 'SAR', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $rule = AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => 'roas_drop', 'name' => 'ROAS', 'threshold' => ['pct' => 25, 'days' => 7],
            'cooldown_minutes' => 720, 'channels' => ['in_app'], 'severity' => 'warning', 'active' => true,
        ]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
    }

    /**
     * The digest's «يحتاج قرارك الآن» judges «صرف بدون نتائج» on the campaigns that may be talked about: a platform whose only
     * zero-result spend came from a campaign stopped mid-period has no live row and no verdict; one with a live zero does.
     */
    public function test_the_digest_cell_judges_no_results_on_live_campaigns_only(): void
    {
        $base = ['spend' => 300.0, 'results' => 0.0, 'results_key' => 'purchases', 'results_reported' => true];

        $this->assertSame('no_results', DigestStatus::cell($base)['reason']);
        $this->assertNotSame('no_results', DigestStatus::cell($base + ['live' => null])['reason']);
        $this->assertSame('no_results', DigestStatus::cell($base + ['live' => ['spend' => 80.0, 'purchases' => 0.0]])['reason']);
        $this->assertNotSame('no_results', DigestStatus::cell(['results' => 3.0] + $base + ['live' => ['spend' => 80.0, 'purchases' => 2.0]])['reason']);
    }
}
