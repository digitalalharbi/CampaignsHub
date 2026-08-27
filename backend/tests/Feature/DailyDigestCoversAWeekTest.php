<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Notifications\Services\DigestDispatcher;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * EMAIL-DAILY-WINDOW-001 — spend from five days ago belongs in today's daily email.
 *
 * The behavioural half of the change. Asserting the constant is 7 only proves I can read my own
 * constant; this proves the digest actually reaches back, by putting spend on a day the OLD window
 * could not have seen and requiring it in the totals.
 */
final class DailyDigestCoversAWeekTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00', 'UTC'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-week-window', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-ww', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-ww']);
        $role->givePermissionTo('clients.view_all');

        $this->user = User::create(['name' => 'Ops', 'email' => 'ops@week.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
        $this->user = $this->user->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spendOn(Carbon $date, float $amount): void
    {
        app(UpsertDailyMetrics::class)->handle([
            new NormalizedMetric(
                tenantId: (string) $this->tenant->id,
                projectId: (string) $this->project->id,
                externalAccountId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'acc-ww')->toString(),
                externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'camp-ww')->toString(),
                provider: 'meta',
                metricKey: 'spend',
                metricDate: $date,
                value: $amount,
                projectCurrency: 'SAR',
                convertedAmount: $amount,
            ),
        ]);
    }

    public function test_the_daily_window_reaches_back_seven_days(): void
    {
        $end = Carbon::today()->subDay();          // the window's last day
        $this->spendOn($end, 100.0);               // 1 day ago
        $this->spendOn($end->copy()->subDays(5), 900.0);  // 6 days ago — outside the OLD window

        $start = $end->copy()->subDays(DigestDispatcher::DAILY_WINDOW_DAYS - 1)->startOfDay();
        $digest = app(DailyDigest::class)->buildRange(
            $this->user,
            (string) $this->tenant->id,
            [(string) $this->project->id],
            $start,
            $end->copy()->endOfDay(),
        );

        $this->assertSame(7, $digest['days'], 'The daily digest must report a seven-day window.');
        $this->assertSame(
            1000.0,
            round((float) ($digest['totals']['spend'] ?? 0), 2),
            'Spend from six days ago is missing — the window did not reach back.'
        );
    }

    public function test_the_comparison_window_is_the_seven_days_before_it(): void
    {
        $end = Carbon::today()->subDay();
        $this->spendOn($end, 100.0);
        // A day inside the PREVIOUS seven — it must land in the comparison, not the current window.
        $this->spendOn($end->copy()->subDays(9), 500.0);

        $start = $end->copy()->subDays(6)->startOfDay();
        $digest = app(DailyDigest::class)->buildRange(
            $this->user,
            (string) $this->tenant->id,
            [(string) $this->project->id],
            $start,
            $end->copy()->endOfDay(),
        );

        $this->assertSame(100.0, round((float) ($digest['totals']['spend'] ?? 0), 2));

        $block = $digest['projects'][0] ?? [];
        $this->assertSame(
            500.0,
            round((float) ($block['previous']['spend'] ?? 0), 2),
            'Spend nine days back belongs to the previous window, which is what the email compares against.'
        );
    }

    public function test_the_previous_window_is_the_same_length_as_the_current_one(): void
    {
        /*
         * DIGEST-PREV-WINDOW-001. A seven-day window was comparing itself against NINE days, because
         * `diffInDays` on an `endOfDay()` returns a float and `prevFrom` counted back from the wrong
         * end. Nine days of spend against seven is not a trend, it is a longer ruler — and it applied
         * to weekly and monthly too.
         *
         * Spend is placed on the two days that were wrongly swept into the comparison. If either
         * leaks in, the previous total exceeds what those seven days actually hold.
         */
        $end = Carbon::today()->subDay();                 // 19 Aug
        $start = $end->copy()->subDays(6)->startOfDay();   // 13 Aug

        $this->spendOn($end, 100.0);                       // current window
        $this->spendOn($start->copy()->subDays(1), 10.0);  // 12 Aug — genuinely previous
        $this->spendOn($start->copy()->subDays(7), 10.0);  // 6 Aug  — genuinely previous (first day)
        $this->spendOn($start->copy()->subDays(8), 999.0); // 5 Aug  — OUTSIDE; the old code swept it in
        $this->spendOn($start->copy()->subDays(9), 999.0); // 4 Aug  — OUTSIDE; the old code swept it in

        $digest = app(DailyDigest::class)->buildRange(
            $this->user,
            (string) $this->tenant->id,
            [(string) $this->project->id],
            $start,
            $end->copy()->endOfDay(),
        );

        $block = $digest['projects'][0] ?? [];

        $this->assertSame(
            20.0,
            round((float) ($block['previous']['spend'] ?? 0), 2),
            'The comparison window swept in days outside it — it is longer than the window it compares.'
        );
    }
}
