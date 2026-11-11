<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Services\DigestDispatcher;
use App\Domains\Notifications\Support\AlertMailItem;
use App\Domains\Notifications\Support\AlertRuleCopy;
use App\Domains\Notifications\Support\DigestDemo;
use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Notifications\Support\OperationalDigestModel;
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

    public function test_the_daily_lands_at_eight_riyadh_which_is_five_utc_and_not_at_eight_utc(): void
    {
        $this->preference(['daily' => true]);

        $this->runAt('2026-11-12 08:00:00');            // 11:00 in Riyadh
        $this->assertSame([], $this->ledger());

        $this->runAt('2026-11-12 05:00:00');            // 08:00 in Riyadh
        $this->assertSame(['daily 2026-11-11'], $this->ledger(), 'the day that ended yesterday, in Riyadh');
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

    public function test_the_per_project_weekly_and_monthly_follow_the_daily_answer_until_set(): void
    {
        $this->assertSame([], DigestSchedule::perProjectKinds([]));
        $this->assertSame(['daily', 'weekly', 'monthly'], DigestSchedule::perProjectKinds(['per_project' => true]));
        $this->assertSame(['daily', 'monthly'], DigestSchedule::perProjectKinds(['per_project' => true, 'per_project_weekly' => false]));
        $this->assertSame(['weekly'], DigestSchedule::perProjectKinds(['per_project_weekly' => true]));
    }

    public function test_a_report_per_project_needs_only_its_own_switch_and_lands_once_per_period(): void
    {
        $this->preference(['per_project' => true]);

        $this->runAt('2026-11-15 05:00:00', force: true);
        $this->runAt('2026-11-15 05:00:00', force: true);   // a retry converges on the same rows

        $k = $this->key();
        $this->assertSame(["daily 2026-11-14~{$k}", "monthly 2026-11~{$k}", "weekly 2026-W46~{$k}"], $this->ledger(), 'no projects-summary row: it was not asked for');
        $this->assertSame(3, DB::table('digest_sends')->where('user_id', $this->user->id)->count());
    }

    public function test_preferences_gate_every_rhythm(): void
    {
        $this->preference(['per_project' => true, 'per_project_weekly' => false, 'per_project_monthly' => false]);
        $this->runAt('2026-12-06 05:00:00', force: true);
        $this->assertSame(['daily 2026-12-05~'.$this->key()], $this->ledger());

        DB::table('notification_preferences')->update(['digests' => json_encode([])]);
        DB::table('digest_sends')->delete();
        $this->runAt('2026-12-07 05:00:00', force: true);
        $this->assertSame([], $this->ledger());
    }

    // ── 2. «ملخص المشاريع», led by the budgets ───────────────────────────────

    public function test_budget_pace_bands_and_money_rules(): void
    {
        $b = static fn (array $over): array => OperationalDigestModel::budget($over + ['budget' => 10000.0, 'spent' => 4000.0, 'currency' => 'SAR', 'currencies' => 1, 'elapsed' => 0.4], 'ar');

        $this->assertSame('on_track', $b(['pace' => 1.0])['band']);
        $this->assertSame('ahead', $b(['pace' => 1.25])['band']);
        $this->assertSame('behind', $b(['pace' => 0.7])['band']);
        $this->assertSame('6,000 SAR', $b(['pace' => 1.0])['remaining']);
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

    public function test_the_projects_summary_leads_with_budgets_and_its_total_adds_up(): void
    {
        $m = OperationalDigestModel::portfolio(DigestDemo::payload(30, 6), 'ar', 'daily');
        $ok = array_filter($m['budgets']['rows'], static fn (array $r): bool => $r['state'] === 'ok');

        $this->assertNotSame([], $ok);
        $this->assertEqualsWithDelta(array_sum(array_column($ok, 'budget_value')), $m['budgets']['total']['budget_value'], 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($ok, 'spent_value')), $m['budgets']['total']['spent_value'], 0.01);
        // A project with no budget, a withheld spend or two currencies is counted outside the total, never dropped silently.
        $this->assertGreaterThan(0, $m['budgets']['left_out']);
        // Closest to running out first.
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
        $this->assertStringContainsString('تقرير المشروع · الشهر', (string) $html);
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

        $row = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/notifications')->assertOk()->json('data'))->firstWhere('id', $n->id);

        $this->assertSame('watch', $row['visual']['state']);
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
        $this->assertTrue($d['per_project_weekly']);
        $this->assertTrue($d['per_project_monthly']);

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/settings/notifications', ['digests' => ['daily' => true, 'per_project_monthly' => false]])->assertOk();
        $d = $get();
        $this->assertTrue($d['daily']);
        $this->assertTrue($d['per_project'], 'a client that did not send per_project cannot erase it');
        $this->assertTrue($d['per_project_weekly']);
        $this->assertFalse($d['per_project_monthly']);
    }
}
