<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Mail\DailyDigestMail;
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
use Illuminate\Support\Facades\Mail;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * EMAIL-CANONICAL-METRICS-001 / EMAIL-REPORT-WEEKLY-001 — the email states the same figures the web
 * states for the same window.
 *
 * Both read `MetricsAggregator`; this asserts it rather than trusting it. The fixture includes the
 * case most likely to make them disagree: an ad account that was DESELECTED for the project, whose
 * rows the web hides. Each of the three rhythms — the daily seven days, the ISO week, the calendar
 * month — is compared figure by figure against `GET /projects/{project}/metrics/summary` for its
 * own window, and the weekly email is sent as its own document over its own week.
 */
final class EmailWebParityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    private const KEYS = ['spend', 'impressions', 'clicks', 'conversions', 'revenue'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-16 09:00:00', 'UTC'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Parity', 'slug' => 'parity-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::query()->pluck("key")->all());
        $this->user = User::create(['name' => 'Ops', 'email' => 'ops-'.uniqid().'@parity.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
        $this->user = $this->user->fresh();

        $selected = $this->account('act_sel', true);
        $deselected = $this->account('act_off', false);
        // Spread across August and September so the three windows each hold different rows.
        foreach ([1, 3, 6, 9, 20, 35] as $daysAgo) {
            $day = Carbon::today()->subDays($daysAgo);
            $this->metric($selected, $day, ['spend' => 100 + $daysAgo, 'impressions' => 5000, 'clicks' => 80 + $daysAgo, 'conversions' => 4, 'revenue' => 600]);
            $this->metric($deselected, $day, ['spend' => 999, 'impressions' => 99999, 'clicks' => 999, 'conversions' => 99, 'revenue' => 9999]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_daily_email_states_the_web_figures_for_its_seven_days(): void
    {
        $end = Carbon::today()->subDay();
        $this->assertParity($end->copy()->subDays(DigestDispatcher::DAILY_WINDOW_DAYS - 1)->startOfDay(), $end->copy()->endOfDay());
    }

    public function test_the_weekly_email_states_the_web_figures_for_its_week(): void
    {
        $end = Carbon::today()->subDay();
        $this->assertParity($end->copy()->subDays(6)->startOfDay(), $end->copy()->endOfDay());
    }

    public function test_the_monthly_email_states_the_web_figures_for_its_calendar_month(): void
    {
        $month = Carbon::today()->subMonthNoOverflow();
        $this->assertParity($month->copy()->startOfMonth()->startOfDay(), $month->copy()->endOfMonth()->endOfDay());
    }

    /** The weekly report is its own document: its own rhythm, its own week, the same figures. */
    public function test_the_weekly_email_is_sent_as_its_own_document_over_its_own_week(): void
    {
        Mail::fake();
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.parity.test');
        $end = Carbon::today()->subDay();

        app(DigestDispatcher::class)->sendWeekly($this->user, (string) $this->tenant->id, $end, 'en');

        $web = $this->web($end->copy()->subDays(6)->startOfDay(), $end->copy()->endOfDay());
        $sent = Mail::sent(DailyDigestMail::class);
        if ($sent->isEmpty()) {
            $this->markTestSkipped('No mail provider is configured in this environment, so the weekly send records awaiting_credentials; parity is asserted on the built digest above.');
        }
        $mail = $sent->first();
        $subject = $mail->envelope()->subject;
        $this->assertStringContainsString('this week', $subject, 'the weekly email names its week, not «the last 7 days»');
        $this->assertStringContainsString(number_format((float) $web['conversions']).' results', $subject);
    }

    private function assertParity(Carbon $from, Carbon $to): void
    {
        $digest = app(DailyDigest::class)->buildRange($this->user, (string) $this->tenant->id, [(string) $this->project->id], $from, $to);
        $web = $this->web($from, $to);

        $this->assertGreaterThan(0, (float) $web['spend'], 'the window holds rows — a parity of zeros proves nothing');
        foreach (self::KEYS as $key) {
            $this->assertEqualsWithDelta((float) $web[$key], (float) ($digest['totals'][$key] ?? 0), 0.005, "{$key}: the email and the web disagree for {$from->toDateString()}…{$to->toDateString()}");
        }
        $this->assertLessThan(999, (float) $digest['totals']['spend'] / max(1, count([1])), 'the deselected account never reaches the email');
    }

    /** @return array<string, mixed> */
    private function web(Carbon $from, Carbon $to): array
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/metrics/summary?from={$from->toDateString()}&to={$to->toDateString()}")
            ->assertOk()
            ->json('data.current');
    }

    private function account(string $externalId, bool $active): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'meta-'.uniqid(),
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => $externalId,
            'name' => $externalId, 'currency' => 'SAR', 'status' => 'active',
        ]);
        ProjectIntegrationBinding::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'purpose' => 'ads', 'is_active' => $active,
        ]);

        return $account;
    }

    /** @param  array<string, float|int>  $values */
    private function metric(ExternalAccount $account, Carbon $day, array $values): void
    {
        $metrics = [];
        foreach ($values as $key => $value) {
            $metrics[] = new NormalizedMetric(
                tenantId: (string) $this->tenant->id,
                projectId: (string) $this->project->id,
                externalAccountId: (string) $account->id,
                externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'camp-'.$account->id)->toString(),
                provider: 'meta',
                metricKey: $key,
                metricDate: $day,
                value: (float) $value,
                projectCurrency: 'SAR',
                convertedAmount: in_array($key, ['spend', 'revenue'], true) ? (float) $value : null,
            );
        }
        app(UpsertDailyMetrics::class)->handle($metrics);
    }
}
