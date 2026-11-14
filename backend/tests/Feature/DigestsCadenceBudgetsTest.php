<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Notifications\Services\DigestDispatcher;
use App\Domains\Notifications\Services\MailSendGate;
use App\Domains\Notifications\Services\MailUnsubscribes;
use App\Domains\Notifications\Support\AlertMailItem;
use App\Domains\Notifications\Support\AlertRuleCopy;
use App\Domains\Notifications\Support\DigestDemo;
use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Notifications\Support\OperationalDigestModel;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * DIGESTS-CADENCE-BUDGETS-001 — the Owner approved the digest templates as THE standard (2026-11-11) and asked for them to
 * reach him: a report per project daily, weekly AND monthly at 08:00 Riyadh; «ملخص المشاريع» led by the budgets on the same
 * three rhythms; and the same visual grammar on every alert and notification, in the mail and in the bell.
 */
final class DigestsCadenceBudgetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-cad-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-cad-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-cad-'.uniqid()]);
        $role->givePermissionTo('clients.view_all');
        $this->user = User::create(['name' => 'Ops', 'email' => 'ops-'.uniqid().'@cad.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string,mixed> $digests */
    private function preference(array $digests, array $over = []): void
    {
        DB::table('notification_preferences')->insert(array_merge([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->user->id,
            'channels' => json_encode(['email' => true]), 'categories' => json_encode([]), 'digests' => json_encode($digests),
            'timezone' => 'Asia/Riyadh', 'locale' => 'ar', 'digest_hour' => 8, 'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    private function runAt(string $utc, bool $force = false): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $this->artisan('notifications:send-digests', $force ? ['--force' => true] : [])->assertSuccessful();
    }

    /** @return list<string> «kind period_key» of every ledger row, sorted */
    private function ledger(): array
    {
        $rows = DB::table('digest_sends')->where('user_id', $this->user->id)->orderBy('kind')->orderBy('period_key')->get(['kind', 'period_key']);

        return $rows->map(static fn ($r): string => $r->kind.' '.$r->period_key)->all();
    }

    private function key(): string
    {
        return DigestDispatcher::projectKey((string) $this->project->id);
    }

    // ── 1. Cadence ───────────────────────────────────────────────────────────

    public function test_the_digest_sweep_is_pinned_to_riyadh(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($e): bool => str_contains((string) $e->command, 'notifications:send-digests')));

        $this->assertCount(1, $events);
        $this->assertSame('Asia/Riyadh', $events[0]->timezone);
    }

    public function test_the_daily_lands_at_eight_riyadh_which_is_five_utc_not_before(): void
    {
        $this->preference(['daily' => true]);

        $this->runAt('2026-11-12 04:00:00');            // 07:00 in Riyadh — not yet
        $this->assertSame([], $this->ledger());

        $this->runAt('2026-11-12 05:00:00');            // 08:00 in Riyadh
        $this->assertSame(['daily 2026-11-11'], $this->ledger(), 'the day that ended yesterday, in Riyadh');

        $this->runAt('2026-11-12 08:00:00');            // a later hour sends nothing twice
        $this->assertSame(['daily 2026-11-11'], $this->ledger());
    }

    /** A sweep that overran its hour must not skip the readers of that hour: a later run the same day catches them up. */
    public function test_a_missed_hour_is_caught_up_later_the_same_day(): void
    {
        $this->preference(['daily' => true]);

        $this->runAt('2026-11-12 07:00:00');            // 10:00 in Riyadh: the 08:00 run never reached this reader
        $this->assertSame(['daily 2026-11-11'], $this->ledger());
    }

    /** `--force` is a manual look: its own on-demand ledger row, never the period's slot. */
    public function test_force_does_not_take_the_scheduled_slot(): void
    {
        $this->preference(['daily' => true]);

        $this->runAt('2026-11-12 02:00:00', force: true);
        $this->assertSame([], array_values(array_filter($this->ledger(), static fn (string $l): bool => str_starts_with($l, 'daily '))));
        $this->assertSame(1, DB::table('digest_sends')->where('user_id', $this->user->id)->where('kind', DigestDispatcher::ON_DEMAND_PREFIX.'daily')->count());

        $this->runAt('2026-11-12 05:00:00');
        $this->assertContains('daily 2026-11-11', $this->ledger(), 'the scheduled send still goes out');
    }

    public function test_a_new_preference_row_sends_the_weekly_on_sunday(): void
    {
        $this->preference(['weekly' => true]);
        $this->assertSame(7, (int) DB::table('notification_preferences')->where('user_id', $this->user->id)->value('digest_weekday'));
        $this->assertSame(7, DigestSchedule::weekday(null));
        $this->assertSame(7, DigestSchedule::weekday(9));

        $this->runAt('2026-11-16 05:00:00');            // Monday
        $this->assertSame([], $this->ledger());

        $this->runAt('2026-11-15 05:00:00');            // Sunday 08:00 Riyadh
        $row = DB::table('digest_sends')->where('user_id', $this->user->id)->where('kind', 'weekly')->first();
        $this->assertNotNull($row);
        // The seven complete days that ended yesterday (Sun 8 – Sat 14), compared by the builder with the seven before.
        $this->assertSame('2026-11-08', Carbon::parse((string) $row->window_from)->toDateString());
        $this->assertSame('2026-11-14', Carbon::parse((string) $row->window_to)->toDateString());
    }

    public function test_the_monthly_on_the_first_reports_the_finished_calendar_month(): void
    {
        $this->preference(['monthly' => true]);

        $this->runAt('2026-12-01 05:00:00');
        $row = DB::table('digest_sends')->where('user_id', $this->user->id)->where('kind', 'monthly')->first();
        $this->assertSame('2026-11', $row->period_key, 'November, not October: the month that just finished');
        $this->assertSame('2026-11-01', Carbon::parse((string) $row->window_from)->toDateString());
        $this->assertSame('2026-11-30', Carbon::parse((string) $row->window_to)->toDateString());
    }

    public function test_month_windows_are_plain_calendar_months_across_every_boundary(): void
    {
        $cases = [
            '2026-12-01' => ['2026-11-01', '2026-11-30', '2026-10-01', '2026-10-31'],
            '2027-01-01' => ['2026-12-01', '2026-12-31', '2026-11-01', '2026-11-30'],   // year boundary
            '2027-03-01' => ['2027-02-01', '2027-02-28', '2027-01-01', '2027-01-31'],   // February
            '2028-03-01' => ['2028-02-01', '2028-02-29', '2028-01-01', '2028-01-31'],   // leap February
            '2026-03-31' => ['2026-02-01', '2026-02-28', '2026-01-01', '2026-01-31'],   // a 31st never overflows
        ];
        foreach ($cases as $send => [$from, $to, $pFrom, $pTo]) {
            $w = DigestSchedule::previousMonth(Carbon::parse($send, 'Asia/Riyadh'));
            $this->assertSame([$from, $to, $pFrom, $pTo], [$w['from']->toDateString(), $w['to']->toDateString(), $w['previous_from']->toDateString(), $w['previous_to']->toDateString()], $send);
        }
    }

    public function test_each_per_project_rhythm_is_its_own_opt_in_and_absent_is_off(): void
    {
        $this->assertSame([], DigestSchedule::perProjectKinds([]));
        // An existing daily reader is NOT fanned out to weekly and monthly by a deploy.
        $this->assertSame(['daily'], DigestSchedule::perProjectKinds(['per_project' => true]));
        $this->assertSame(['daily', 'monthly'], DigestSchedule::perProjectKinds(['per_project' => true, 'per_project_monthly' => true]));
        $this->assertSame(['weekly'], DigestSchedule::perProjectKinds(['per_project_weekly' => true]));
    }

    public function test_a_report_per_project_needs_only_its_own_switch_and_lands_once_per_period(): void
    {
        $this->preference(['per_project' => true, 'per_project_weekly' => true, 'per_project_monthly' => true]);

        // Sunday 1 November 2026, 08:00 Riyadh: daily, weekly (Sunday) and monthly (the 1st) are all due.
        $this->runAt('2026-11-01 05:00:00');
        $this->runAt('2026-11-01 09:00:00');   // a later hour converges on the same rows

        $k = $this->key();
        $this->assertSame(["daily 2026-10-31~{$k}", "monthly 2026-10~{$k}", "weekly 2026-W44~{$k}"], $this->ledger(), 'no projects-summary row: it was not asked for');
    }

    public function test_preferences_gate_every_rhythm(): void
    {
        $this->preference(['per_project' => true]);
        $this->runAt('2026-11-01 05:00:00');
        $this->assertSame(['daily 2026-10-31~'.$this->key()], $this->ledger(), 'weekly and monthly were never chosen');

        DB::table('notification_preferences')->update(['digests' => json_encode([])]);
        $this->runAt('2026-11-02 05:00:00');
        $this->assertSame(['daily 2026-10-31~'.$this->key()], $this->ledger());
    }

    /** Leaving ONE project report leaves that rhythm of the per-project report — not the projects summary — and costs no build. */
    public function test_a_per_project_report_has_its_own_unsubscribe_asked_before_anything_is_built(): void
    {
        $this->preference(['daily' => true, 'per_project' => true]);
        $unsubscribes = app(MailUnsubscribes::class);
        $this->assertSame('project_daily_digest', MailUnsubscribes::projectScope('daily'));
        $this->assertSame('project_weekly_digest', MailUnsubscribes::projectScope('weekly'));

        $token = $unsubscribes->read((string) Str::afterLast((string) $unsubscribes->url((string) $this->tenant->id, (string) $this->user->email, 'project_daily_digest', $this->user->id), '/'));
        $this->assertNotNull($token);
        $unsubscribes->leave($token);

        $digests = (array) json_decode((string) DB::table('notification_preferences')->where('user_id', $this->user->id)->value('digests'), true);
        $this->assertFalse($digests['per_project']);
        $this->assertTrue($digests['daily'], 'the projects summary stays on');

        // A run before the switch-off is read still refuses at the gate, before any figure is built.
        DB::table('notification_preferences')->update(['digests' => json_encode(['daily' => true, 'per_project' => true])]);
        $this->runAt('2026-11-12 05:00:00');
        $row = DB::table('digest_sends')->where('user_id', $this->user->id)->where('period_key', '2026-11-11~'.$this->key())->first();
        $this->assertSame('unsubscribed', $row->status);

        // Switching it back on in settings lifts the old click.
        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/settings/notifications', ['digests' => ['per_project' => true]])->assertOk();
        $this->assertFalse($unsubscribes->hasLeft((string) $this->tenant->id, (string) $this->user->email, 'project_daily_digest'));
    }

    /** A failed report per project is retried for a reader who wants it, by ITS switch — not dropped as «no longer wanted». */
    public function test_a_failed_per_project_report_is_retried_by_its_own_switch(): void
    {
        $this->preference(['per_project' => true]);
        DB::table('digest_sends')->insert([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->user->id, 'kind' => 'daily',
            'period_key' => '2026-11-11~'.$this->key(), 'status' => 'failed', 'attempts' => 1, 'next_attempt_at' => Carbon::parse('2026-11-12 04:00:00'),
            'window_from' => '2026-11-05 00:00:00', 'window_to' => '2026-11-11 23:59:59', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $counts = app(DigestDispatcher::class)->retryDue(Carbon::parse('2026-11-12 05:00:00'));

        $this->assertArrayNotHasKey('no_longer_wanted', $counts);
        $this->assertNotSame('failed', DB::table('digest_sends')->where('user_id', $this->user->id)->value('status'));
    }

    /** Digests leave part of the tenant's daily allowance for alerts. */
    public function test_digests_cannot_spend_the_allowance_alerts_need(): void
    {
        config(['mail.daily_limit_per_tenant' => 3, 'mail.alert_reserve_per_tenant' => 2]);
        $gate = app(MailSendGate::class);
        $t = (string) $this->tenant->id;

        $this->assertNull($gate->refusal($t, 'a@x.test', 'daily_digest', reserve: 2));
        $this->assertSame(MailSendGate::LIMIT_REACHED, $gate->refusal($t, 'a@x.test', 'daily_digest', reserve: 2));
        $this->assertNull($gate->refusal($t, 'a@x.test', MailUnsubscribes::ALERTS), 'an alert still goes');
        $this->assertNull($gate->refusal($t, 'a@x.test', MailUnsubscribes::ALERTS));
    }

    public function test_budget_pace_bands_and_money_rules(): void
    {
        $b = static fn (array $over): array => OperationalDigestModel::budget($over + ['budget' => 10000.0, 'spent' => 4000.0, 'currency' => 'SAR', 'currencies' => 1, 'elapsed' => 0.4], 'ar');

        $this->assertSame('on_track', $b(['pace' => 1.0])['band']);
        $this->assertSame('ahead', $b(['pace' => 1.25])['band']);
        $this->assertSame('behind', $b(['pace' => 0.7])['band']);
        $this->assertSame('6,000 ر.س', $b(['pace' => 1.0])['remaining']);
        $this->assertSame(40, $b(['pace' => 1.0])['used']);

        $over = $b(['spent' => 11000.0, 'pace' => 1.1]);
        $this->assertSame('over', $over['band']);
        $this->assertTrue($over['over']);
        $this->assertSame('تجاوزت الميزانية', $over['band_label']);

        // A spend that arrived without an exchange rate is «no data», never a zero and never a short bar.
        $withheld = $b(['spent' => null, 'pace' => null]);
        $this->assertSame('withheld', $withheld['state']);
        $this->assertSame('لا بيانات', $withheld['spent']);
        $this->assertSame('لا بيانات', $withheld['remaining']);

        $this->assertSame('mixed', $b(['currencies' => 2])['state']);
        $this->assertSame('none', $b(['budget' => null])['state']);
    }

    private function campaign(string $name, float $budget, string $currency): UnifiedCampaign
    {
        return UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => $name, 'status' => 'active', 'total_budget' => $budget, 'budget_currency' => $currency]);
    }

    /** @param  array<string,mixed>  $over */
    private function spend(UnifiedCampaign $c, array $over): void
    {
        DailyMetric::create(array_merge([
            'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $c->id, 'provider' => 'meta',
            'metric_key' => 'spend', 'metric_date' => '2026-11-05', 'project_currency' => 'SAR',
        ], $over));
    }

    /** The project's budget block as the REAL path builds it: `budgetPacing()` → `ClientBudgetRollup` → the model. */
    private function budgetBlock(): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        try {
            $digest = app(DailyDigest::class)->buildRange($this->user, (string) $this->tenant->id, [(string) $this->project->id], Carbon::parse('2026-11-05'), Carbon::parse('2026-11-11')->endOfDay(), 'daily');
        } finally {
            app(TenantContext::class)->forget();
        }

        return OperationalDigestModel::project($digest['projects'][0], 'ar')['budget'];
    }

    public function test_a_budget_whose_spend_is_in_another_currency_is_no_figure_with_its_reason_not_no_budget(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->spend($this->campaign('USD account', 5000.0, 'USD'), ['value' => 3000.0]);   // budget USD, spend SAR

        $b = $this->budgetBlock();

        $this->assertSame('unpriced', $b['state'], 'the project HAS a budget');
        $this->assertSame('لا بيانات', $b['remaining']);
        $this->assertStringContainsString('عملة الإنفاق غير عملة الميزانية', $b['line']);
        $this->assertStringNotContainsString('ما فيه ميزانية', $b['line']);
    }

    public function test_spend_with_no_exchange_rate_names_that_reason(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $c = $this->campaign('EUR spend', 5000.0, 'SAR');
        $this->spend($c, ['value' => 1000.0]);
        $this->spend($c, ['value' => null, 'original_amount' => 400.0, 'original_currency' => 'EUR', 'metric_date' => '2026-11-06']);   // partial: no rate

        $b = $this->budgetBlock();

        $this->assertSame('unpriced', $b['state']);
        $this->assertStringContainsString('بدون سعر صرف', $b['line']);
    }

    public function test_a_campaign_left_out_of_the_figure_is_counted_on_the_row(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->spend($this->campaign('Priced', 10000.0, 'SAR'), ['value' => 4000.0]);
        $this->spend($this->campaign('USD account', 5000.0, 'USD'), ['value' => 3000.0]);

        $b = $this->budgetBlock();

        $this->assertSame('ok', $b['state']);
        $this->assertSame(10000.0, $b['budget_value'], 'only the priced campaign is in the figure');
        $this->assertSame(1, $b['outside']);
        $this->assertStringContainsString('حملة وحدة خارج الرقم', $b['outside_line']);
        $this->assertStringContainsString('عملة الإنفاق غير عملة الميزانية', $b['outside_line']);
    }

    public function test_the_projects_summary_leads_with_budgets_and_its_total_adds_up(): void
    {
        $m = OperationalDigestModel::portfolio(DigestDemo::payload(30, 6), 'ar', 'daily');
        $ok = array_filter($m['budgets']['rows'], static fn (array $r): bool => $r['state'] === 'ok');

        $this->assertNotSame([], $ok);
        $this->assertEqualsWithDelta(array_sum(array_column($ok, 'budget_value')), $m['budgets']['total']['budget_value'], 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($ok, 'spent_value')), $m['budgets']['total']['spent_value'], 0.01);
        // A project with no budget, a withheld spend or two currencies is counted outside the total, never dropped silently.
        $this->assertGreaterThan(0, $m['budgets']['left_out']);
        // A project with no statable figure first, then closest to running out.
        $states = array_column($m['budgets']['rows'], 'state');
        $firstOk = array_search('ok', $states, true);
        $this->assertSame([], array_filter(array_slice($states, (int) $firstOk), static fn (string $st): bool => $st !== 'ok'));
        $used = array_values(array_filter(array_column($m['budgets']['rows'], 'used'), 'is_int'));
        $sorted = $used;
        rsort($sorted);
        $this->assertSame($sorted, $used);

        $html = MailGallery::render('digest-projects-daily', 'ar');
        $this->assertNotNull($html);
        $this->assertLessThan(strpos($html, 'data-block="counts"'), strpos($html, 'data-block="budgets"'), 'budgets come before the heatmap counts');
        $this->assertStringContainsString('ملخص المشاريع', $html);
    }

    public function test_two_budget_currencies_give_no_total(): void
    {
        $d = DigestDemo::payload(3, 4);
        $d['projects'][1]['budget_month']['currency'] = 'USD';
        $m = OperationalDigestModel::portfolio($d, 'ar', 'daily');

        $this->assertNull($m['budgets']['total']);
        $this->assertNotNull($m['budgets']['total_reason']);
    }

    public function test_the_monthly_draws_the_month_as_its_weeks(): void
    {
        $series = static fn (string $month, int $days): array => array_map(static fn (int $d): array => ['date' => sprintf('%s-%02d', $month, $d), 'spend' => 100.0], range(1, $days));

        $oct = OperationalDigestModel::weeks($series('2026-10', 31), true);
        $this->assertSame(['1–7', '8–14', '15–21', '22–28', '29–31'], array_column($oct, 'label'));
        $this->assertCount(4, OperationalDigestModel::weeks($series('2027-02', 28), true));

        $html = MailGallery::render('digest-project-monthly', 'ar');
        $this->assertStringContainsString('data-block="weeks"', (string) $html);
        $this->assertStringContainsString('data-block="budget"', (string) $html);
        $this->assertStringContainsString('ملخص المشروع • الشهر الماضي', (string) $html);
    }

    // ── 3. Every alert and notification in the approved grammar ─────────────

    /** @return list<string> */
    private function alertTypes(): array
    {
        return array_values(array_unique([
            'budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop', 'no_results', 'sync_failure', 'sync_outage',
            'measurement_outage', 'token_expiry', 'metric_anomaly', ...AlertRuleCopy::TYPES,
        ]));
    }

    public function test_every_alert_type_is_drawn_with_chip_steps_and_its_own_button(): void
    {
        foreach (['ar' => true, 'en' => false] as $lang => $ar) {
            foreach ($this->alertTypes() as $type) {
                $item = AlertMailItem::build([
                    'rule_type' => $type, 'severity' => 'warning', 'context' => ['count' => 3], 'project_id' => (string) $this->project->id,
                    'project_name' => 'P', 'event_id' => 'e-'.$type, 'triggered_at' => '2026-11-11T05:40:00Z',
                ], $ar);
                $html = (new AlertBundleMail([$item], $lang))->render();

                $this->assertStringContainsString('data-chip="status"', $html, $type);
                $this->assertStringContainsString('data-block="viz"', $html, "{$type} draws its problem");
                $this->assertStringContainsString('data-block="steps"', $html, "{$type} says what to do, in order");
                foreach (['افتحه وقرّر', 'فيه تنبيه جديد', 'افتح التنبيه', 'Open the alert and decide', 'A new alert needs your review', 'Open the alert'] as $generic) {
                    $this->assertStringNotContainsString($generic, $html, "{$type} ({$lang}) fell back to «{$generic}»");
                }
            }
        }
    }

    public function test_an_operational_message_is_the_same_card_and_never_a_paragraph(): void
    {
        $html = (new OperationalMail('report_ready', 'positive', 'تقرير جاهز', "السطر الأول من التفاصيل.\nسطر ثانٍ\nسطر ثالث", 'متجر', 'ar'))->render();

        $this->assertStringContainsString('data-block="alert-card"', $html);
        $this->assertStringContainsString('data-viz="steps"', $html);
        $this->assertStringContainsString('data-block="more"', $html);
        $this->assertStringNotContainsString('white-space:pre-line', $html);
    }

    public function test_the_bell_draws_an_alert_from_its_event(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $n = AppNotification::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $this->user->id,
            'type' => 'budget_risk', 'severity' => 'warning', 'title' => 'ROAS fell', 'status' => 'unread', 'dedup_key' => (string) Str::uuid()]);
        $rule = AlertRule::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'type' => 'roas_drop', 'name' => 'R', 'active' => true]);
        AlertEvent::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'rule_id' => $rule->id, 'type' => 'roas_drop',
            'dedup_key' => hash('sha256', (string) Str::uuid()), 'status' => 'open', 'severity' => 'warning', 'notification_id' => $n->id,
            'context' => ['roas_previous' => 4.5, 'roas_current' => 3.1], 'last_triggered_at' => Carbon::now()]);
        app(TenantContext::class)->forget();

        $read = fn (): array => collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/notifications')->assertOk()->json('data'))->firstWhere('id', $n->id);

        // MEMBER-VISIBILITY-001 — a reader the project withholds the money from sees the stages, never the figures.
        $row = $read();
        $this->assertSame('watch', $row['visual']['state']);
        $this->assertSame('steps', $row['visual']['viz']['type']);
        $this->assertStringNotContainsString('4.50', (string) json_encode($row['visual']));

        // A reader who may see the money sees the movement drawn, and the steps.
        Role::query()->where('tenant_id', $this->tenant->id)->first()->givePermissionTo('settings.manage');
        $this->user->refresh();
        app()->forgetInstance(ProjectAbilities::class);   // the engine memoises per request; a test spans two
        $row = $read();
        $this->assertSame('before_now', $row['visual']['viz']['type']);
        $this->assertNotSame([], $row['visual']['steps']);
    }

    // ── Style guide and naming ───────────────────────────────────────────────

    public function test_no_rendered_mail_says_portfolio_or_a_banned_word(): void
    {
        $banned = ['المحفظة', 'محفظة المشاريع', 'العتبة', 'المستهلك', 'الأصليون', 'الإبداعات', 'المنصرف', 'المصروف', 'Portfolio'];
        foreach (['ar', 'en'] as $lang) {
            foreach (array_keys(MailGallery::messages($lang)) as $key) {
                if (! str_starts_with($key, 'digest-') && ! str_starts_with($key, 'alert')) {
                    continue;
                }
                $html = (string) MailGallery::render($key, $lang);
                foreach ($banned as $word) {
                    $this->assertStringNotContainsString($word, $html, "«{$word}» in {$key}.{$lang}");
                }
            }
        }
    }

    // ── 4. Settings: the switches exist and a save cannot erase them ─────────

    public function test_settings_expose_the_per_project_rhythms_and_a_partial_save_keeps_them(): void
    {
        $this->preference(['per_project' => true]);
        $get = fn () => $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/settings/notifications')->assertOk()->json('data.digests');

        $d = $get();
        $this->assertFalse($d['per_project_weekly'], 'never chosen, so off — no fan-out for an existing reader');
        $this->assertFalse($d['per_project_monthly']);

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/settings/notifications', ['digests' => ['daily' => true, 'per_project_weekly' => true]])->assertOk();
        $d = $get();
        $this->assertTrue($d['daily']);
        $this->assertTrue($d['per_project'], 'a client that did not send per_project cannot erase it');
        $this->assertTrue($d['per_project_weekly']);
        $this->assertFalse($d['per_project_monthly']);
    }
}
