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
use App\Domains\Notifications\Services\DigestPresenter;
use App\Domains\Notifications\Support\AlertEventText;
use App\Domains\Notifications\Support\DigestStatus;
use App\Domains\Notifications\Support\OperationalDigestModel;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ALERT-CRITERIA-001 — OWNER_NOTIFICATIONS_RULES §10أ: every performance verdict checks, in order, running → since when →
 * reached its end → setup blocking spend → payment → only then the objective's own decline; says only what is proven; names
 * platform, ad account, campaign (and ad set), objective, the result's unit, spend with its currency, the period's dates, the
 * status and since when; and orders the solutions «المحتوى ← التتبع ← رصيد الدفع ← الإيقاف».
 *
 * The clock is 08:15 Riyadh on 13 Nov: «yesterday» is 12 Nov.
 */
final class AlertCriteriaPerObjectiveTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 05:15:00', 'UTC'));
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-criteria', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rule(string $type, array $threshold): AlertRule
    {
        return AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => $type, 'name' => $type, 'threshold' => $threshold,
            'cooldown_minutes' => 720, 'channels' => ['in_app', 'email'], 'severity' => 'warning', 'active' => true,
        ]);
    }

    private function campaign(string $objective = 'conversions', string $status = 'active'): UnifiedCampaign
    {
        $c = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'حملة '.uniqid(),
            'objective' => $objective, 'status' => $status, 'budget_currency' => 'SAR', 'meta' => ['adopted_from' => 'sync'],
        ]);
        DB::table('unified_campaigns')->where('id', $c->id)->update(['updated_at' => '2026-11-05 10:00:00+00']);

        return $c->fresh();
    }

    /** The platform campaign behind ours, its ad account (named) and, when asked, one ad set and ads with review answers. */
    private function platform(UnifiedCampaign $c, string $status, array $reviews = [], ?string $bid = null, bool $synced = true): string
    {
        $credential = new IntegrationCredential(['provider' => 'snapchat', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::create(['tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snapchat', 'scope' => 'project_only', 'status' => 'connected']);
        $account = (string) Str::uuid();
        DB::table('external_accounts')->insert([
            'id' => $account, 'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'snapchat',
            'account_type' => 'ad_account', 'external_id' => 'acct-'.uniqid(), 'name' => 'حساب كرم', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ext = (string) Str::uuid();
        DB::table('external_campaigns')->insert([
            'id' => $ext, 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'unified_campaign_id' => $c->id,
            'external_account_id' => $account, 'provider' => 'snapchat', 'external_id' => 'ext-'.uniqid(), 'name' => $c->name, 'status' => $status,
            'last_synced_at' => '2026-11-13 04:00:00+00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $set = (string) Str::uuid();
        DB::table('external_ad_sets')->insert([
            'id' => $set, 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $ext,
            'unified_campaign_id' => $c->id, 'provider' => 'snapchat', 'external_id' => 'set-'.uniqid(), 'name' => 'مجموعة الرياض',
            'status' => 'active', 'bid_strategy' => $bid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($reviews as $review) {
            DB::table('external_ads')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_ad_set_id' => $set,
                'external_campaign_id' => $ext, 'unified_campaign_id' => $c->id, 'provider' => 'snapchat', 'external_id' => 'ad-'.uniqid(),
                'name' => 'إعلان', 'status' => 'active', 'review_status' => $review, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($synced) {
            DB::table('metric_sync_runs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account,
                'provider' => 'snapchat', 'status' => 'success', 'window_start' => '2026-11-01', 'window_end' => '2026-11-12', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $account;
    }

    private function day(UnifiedCampaign $c, string $date, array $figures): void
    {
        foreach ($figures + ['impressions' => 2000] as $key => $value) {
            DB::table('daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $c->id, 'provider' => 'snapchat', 'metric_key' => $key, 'metric_date' => $date, 'value' => $value,
                'project_currency' => 'SAR', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** §10أ — the card: platform, account, campaign + ad set, objective, the unit by name, spend WITH currency, dates, status since. */
    public function test_a_no_results_alert_carries_the_full_card_and_ordered_solutions(): void
    {
        $c = $this->campaign();
        $this->platform($c, 'active');
        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $d) {
            $this->day($c, $d, ['spend' => 40, 'conversions' => 0]);
        }
        $rule = $this->rule('no_results', ['days' => 3]);
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));

        $event = AlertEvent::where('rule_id', $rule->id)->firstOrFail();
        $card = $event->context['card'];
        $this->assertSame(['snapchat'], $card['providers']);
        $this->assertSame(['حساب كرم'], $card['accounts']);
        $this->assertSame('مجموعة الرياض', $card['ad_set']);
        $this->assertSame('SAR', $card['currency']);
        $this->assertSame(['2026-11-10', '2026-11-12', 'active', '2026-11-10'], [$card['from'], $card['to'], $card['status'], $card['active_since']]);

        $c2 = AlertEventText::compose(['rule_type' => 'no_results', 'severity' => 'warning', 'context' => $event->context, 'project_id' => (string) $this->project->id,
            'project_name' => 'كرم النخيل', 'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $c->id, 'event_id' => (string) $event->id, 'triggered_at' => ''], true);
        $this->assertStringContainsString('حساب كرم', $c2['context']);
        $this->assertStringContainsString('SAR', $c2['numbers']);
        $this->assertStringContainsString('طلب', $c2['numbers']);
        $this->assertStringContainsString('نوفمبر', $c2['numbers']);
        $this->assertStringContainsString('شغّالة من', $c2['numbers']);
        $this->assertStringContainsString('المجموعة: مجموعة الرياض', $c2['secondary']);
        // Content first, pause last — never «أوقف» first.
        $this->assertStringStartsWith('راجع المحتوى', $c2['action']);
        $this->assertStringEndsWith('أوقفها إن لزم', $c2['action']);
    }

    /** §6 — «توقفت أمس», once: delivered the day before yesterday, not yesterday, its platform says paused. Closes silently the next day. */
    public function test_stopped_yesterday_is_said_once_and_closes_silently(): void
    {
        $c = $this->campaign();
        $this->platform($c, 'paused');
        $this->day($c, '2026-11-10', ['spend' => 60, 'conversions' => 1]);
        $this->day($c, '2026-11-11', ['spend' => 60, 'conversions' => 1]);
        $rule = $this->rule('no_results', ['days' => 3]);
        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(1, $evaluator->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->where('type', 'campaign_stopped')->firstOrFail();
        $this->assertSame('info', $event->severity);

        // Later the same day: not said again.
        Carbon::setTestNow(Carbon::parse('2026-11-13 11:15:00', 'UTC'));
        $this->assertSame(0, $evaluator->evaluateRule($rule));
        $this->assertSame(1, AlertEvent::where('rule_id', $rule->id)->where('type', 'campaign_stopped')->count());

        // The next day: closed, silently.
        Carbon::setTestNow(Carbon::parse('2026-11-14 05:15:00', 'UTC'));
        $this->assertSame(0, $evaluator->evaluateRule($rule));
        $this->assertSame('resolved', $event->fresh()->status);
        $this->assertSame('notice_over', $event->fresh()->context['closed_reason']);
        $this->assertArrayNotHasKey('recovered_at', $event->fresh()->context);
    }

    /** §10أ check 4 — running, figures arrived, nothing spent, every ad rejected: «ما تصرف» with the proven reason; the rest «غير متاح». */
    public function test_not_spending_states_the_proven_setup_reason_and_what_is_not_available(): void
    {
        $c = $this->campaign();
        $this->platform($c, 'active', ['rejected', 'rejected'], 'target_cost');
        $this->day($c, '2026-11-09', ['spend' => 60, 'conversions' => 0]);
        $rule = $this->rule('no_results', ['days' => 3]);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->where('type', 'not_spending')->firstOrFail();
        $setup = $event->context['checks']['setup'];
        $this->assertSame(['rejected', 'target_cost', 'not_available'], [$setup['ads'], $setup['bid_limit'], $setup['audience']]);
        $this->assertSame('not_available', $event->context['checks']['payment']);

        $say = AlertEventText::plain('not_spending', $event->context, true);
        $this->assertStringContainsString('مرفوضة', $say['text']);
        $this->assertStringContainsString('رصيد الدفع: غير متاح', $say['text']);
        $this->assertStringStartsWith('راجع سبب الرفض', $say['action']);
    }

    /**
     * Review (P1) — «ما تصرف» is one message per episode. A sweep that cannot judge (yesterday's sync late) holds it rather than
     * closing it, and a notice closed quietly is not sent again while the campaign's last delivered day has not moved.
     */
    public function test_not_spending_is_said_once_per_episode(): void
    {
        $c = $this->campaign();
        $this->platform($c, 'active');
        $this->day($c, '2026-11-10', ['spend' => 60, 'conversions' => 0]);
        $rule = $this->rule('no_results', ['days' => 3]);
        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(1, $evaluator->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->where('type', 'not_spending')->firstOrFail();

        // The next day, before its figures arrived: held open, not closed.
        Carbon::setTestNow(Carbon::parse('2026-11-14 05:15:00', 'UTC'));
        $this->assertSame(0, $evaluator->evaluateRule($rule));
        $this->assertSame('open', $event->fresh()->status);

        // Closed by hand; the campaign still spends nothing and its last delivered day is the same: not said again.
        $evaluator->resolve($event->fresh());
        DB::table('metric_sync_runs')->update(['window_end' => '2026-11-13']);
        Carbon::setTestNow(Carbon::parse('2026-11-14 20:15:00', 'UTC'));
        $this->assertSame(0, $evaluator->evaluateRule($rule));
        $this->assertSame(1, AlertEvent::where('rule_id', $rule->id)->where('type', 'not_spending')->count());
    }

    /** Review (P1/P2) — «توقفت أمس» needs the platform's own word and yesterday's figures: a paused row of ours or a late sync is not proof. */
    public function test_stopped_yesterday_needs_the_platform_and_yesterdays_figures(): void
    {
        $manual = $this->campaign('conversions', 'paused');
        $manual->forceFill(['meta' => ['status_source' => 'manual']])->save();
        $this->platform($manual, 'active');
        $late = $this->campaign();
        $this->platform($late, 'paused', synced: false);
        foreach ([$manual, $late] as $c) {
            $this->day($c, '2026-11-11', ['spend' => 60, 'conversions' => 1]);
        }

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('no_results', ['days' => 3])));
    }

    /** Review (P2) — «كل إعلاناتها مرفوضة» only when every ad is rejected; an ad with no review answer (paused) breaks the claim. */
    public function test_rejected_ads_are_claimed_only_when_every_ad_is_rejected(): void
    {
        $c = $this->campaign();
        $this->platform($c, 'active', ['rejected', null]);
        $this->day($c, '2026-11-10', ['spend' => 60, 'conversions' => 0]);
        $rule = $this->rule('no_results', ['days' => 3]);

        app(AlertEvaluator::class)->evaluateRule($rule);
        $event = AlertEvent::where('rule_id', $rule->id)->where('type', 'not_spending')->firstOrFail();
        $this->assertNull($event->context['checks']['setup']['ads']);
    }

    /** Not spending without a linked platform proves nothing about the connection: no «ما تصرف». An unsynced day: no verdict either. */
    public function test_not_spending_needs_a_linked_platform_whose_figures_arrived(): void
    {
        $unlinked = $this->campaign();
        $this->day($unlinked, '2026-11-10', ['spend' => 60, 'conversions' => 0]);
        $unsynced = $this->campaign();
        $this->platform($unsynced, 'active', synced: false);
        $this->day($unsynced, '2026-11-10', ['spend' => 60, 'conversions' => 0]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('no_results', ['days' => 3])));
    }

    /** §10أ check 6 per objective — ROAS and cost per order are sales figures; a traffic campaign is never told its ROAS fell. */
    public function test_roas_drop_judges_only_sales_campaigns(): void
    {
        $sales = $this->campaign('conversions');
        $traffic = $this->campaign('traffic');
        foreach ([$sales, $traffic] as $c) {
            $this->platform($c, 'active');
            $this->day($c, '2026-11-03', ['spend' => 100, 'revenue' => 400, 'conversions' => 8]);
            $this->day($c, '2026-11-12', ['spend' => 100, 'revenue' => 100, 'conversions' => 2]);
        }
        $rule = $this->rule('roas_drop', ['days' => 7]);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $this->assertSame((string) $sales->id, (string) AlertEvent::where('rule_id', $rule->id)->value('entity_id'));
    }

    /** A comparison against too few previous results is noise: 3 orders before is not enough to call a rise in cost per order. */
    public function test_a_cost_rise_needs_enough_previous_results(): void
    {
        $c = $this->campaign('conversions');
        $this->platform($c, 'active');
        $this->day($c, '2026-11-03', ['spend' => 30, 'conversions' => 3]);
        $this->day($c, '2026-11-12', ['spend' => 100, 'conversions' => 1]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('cpa_increase', ['days' => 7])));
    }

    /** A rule without its own percentage takes the family's default; a rule that states one keeps it. */
    public function test_the_family_default_threshold_applies_unless_the_rule_sets_one(): void
    {
        $c = $this->campaign('conversions');
        $this->platform($c, 'active');
        // CPA 10 → 12: a 20% rise. Below the sales default (25%), past a rule's own 15%.
        $this->day($c, '2026-11-03', ['spend' => 100, 'conversions' => 10]);
        $this->day($c, '2026-11-12', ['spend' => 120, 'conversions' => 10]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('cpa_increase', ['days' => 7])));
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule('cpa_increase', ['days' => 7, 'pct' => 15])));
    }

    /** The digest's «صرف بدون نتائج» figure names the scope it was judged on, so it cannot contradict the platform's own tiles. */
    public function test_the_digest_no_results_figure_names_the_running_campaigns_scope(): void
    {
        $verdict = DigestStatus::cell(['spend' => 300.0, 'results' => 3.0, 'results_key' => 'purchases', 'results_reported' => true, 'live' => ['spend' => 80.0, 'purchases' => 0.0]]);
        $this->assertSame('no_results', $verdict['reason']);

        $model = new \ReflectionMethod(OperationalDigestModel::class, 'issue');
        $presenter = (new DigestPresenter('ar', 1));
        $issue = $model->invoke(null, $verdict, 'snapchat', 'سناب شات', ['spend' => 80.0, '_live' => true], [], null, 'cpa', 'purchases', $presenter, true, (string) $this->project->id, 'P', 0, 1, []);
        $this->assertStringStartsWith('الحملات الشغّالة: ', $issue['figure']);
    }
}
