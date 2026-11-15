<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Support\ReportPeriod;
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
 * EMAIL-CANONICAL-METRICS-001 — the email and the web state the same figure AND the same unit for the
 * same project and window, across SAR and USD rows, with deselected and re-bound accounts left out.
 *
 * `EmailWebMetricParityTest` pins four sums for one clean project. This holds the cases where a second
 * reading would drift: account visibility (the reader is `MetricsAggregator`, not the digest), a week
 * and its previous window, two projects in two currencies, and the unit the figures are printed under.
 */
final class EmailWebMetricParityScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-scope', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->client = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-scope', 'mode' => 'managed']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-scope']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'Reader', 'email' => 'reader-scope@agency.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
    }

    public function test_deselected_and_rebound_accounts_are_left_out_of_the_email_exactly_as_the_web_leaves_them_out(): void
    {
        $alpha = $this->project('Alpha');
        $beta = $this->project('Beta');
        $day = Carbon::today()->subDay();

        $kept = $this->account('act-kept');
        $deselected = $this->account('act-deselected');
        $rebound = $this->account('act-rebound');
        $this->bind($alpha, $kept, true);
        $this->bind($alpha, $deselected, false);
        $this->bind($beta, $rebound, true);   // active on Beta — its rows filed under Alpha are Beta's figures now
        $this->bind($alpha, $rebound, false);

        $this->spend($alpha, $kept, 'c-kept', 100.0, $day, 'USD');
        $this->spend($alpha, $deselected, 'c-deselected', 7000.0, $day, 'USD');
        $this->spend($alpha, $rebound, 'c-rebound', 900.0, $day, 'USD');
        // An account that holds no binding anywhere: the web's rule decides, and the email must follow it, whichever way it is set.
        $this->spend($alpha, (string) Uuid::uuid4(), 'c-unbound', 30.0, $day, 'USD');

        $web = app(MetricsAggregator::class)->forProjects([(string) $alpha->id])->totals($day->copy()->startOfDay(), $day->copy()->endOfDay());
        $email = app(DailyDigest::class)->build($this->user, (string) $this->tenant->id, [(string) $alpha->id], $day)['projects'][0]['totals'];

        $this->assertEquals($web['spend'], $email['spend'], 'the email spend differs from the web spend');
        $this->assertGreaterThanOrEqual(100.0, (float) $email['spend']);
        $this->assertLessThan(900.0, (float) $email['spend'], 'a deselected or re-bound account leaked into the email');
    }

    public function test_a_week_and_its_previous_window_are_the_webs_for_the_same_dates(): void
    {
        $alpha = $this->project('Alpha');
        $acct = $this->account('act-week');
        $this->bind($alpha, $acct, true);
        $to = Carbon::today()->subDay()->endOfDay();
        $from = $to->copy()->subDays(6)->startOfDay();
        foreach (range(0, 13) as $i) {
            $this->spend($alpha, $acct, 'c-week', 10.0 * ($i + 1), $to->copy()->subDays($i)->startOfDay(), 'USD');
        }

        $agg = app(MetricsAggregator::class)->forProjects([(string) $alpha->id]);
        $digest = app(DailyDigest::class)->buildRange($this->user, (string) $this->tenant->id, [(string) $alpha->id], $from, $to);
        $block = $digest['projects'][0];

        $this->assertEquals($agg->totals($from, $to)['spend'], $block['totals']['spend']);
        $this->assertEquals($agg->totals($from->copy()->subDays(7), $from->copy()->subSecond())['spend'], $block['previous']['spend']);
        $this->assertEquals($block['totals']['spend'], $digest['totals']['spend'], 'the roll-up of one project is that project');
    }

    public function test_a_monthly_email_compares_with_the_window_the_monthly_report_compares_with(): void
    {
        $alpha = $this->project('Alpha');
        $acct = $this->account('act-month');
        $this->bind($alpha, $acct, true);
        $from = Carbon::parse('2026-10-01')->startOfDay();
        $to = Carbon::parse('2026-10-31')->endOfDay();
        // 31 October days at 10, September at 100 a day, and one August day (31 Aug) at 5,000 that «31 days before» would sweep in.
        foreach (range(0, 30) as $i) {
            $this->spend($alpha, $acct, 'c-month', 10.0, $from->copy()->addDays($i), 'USD');
        }
        foreach (range(0, 29) as $i) {
            $this->spend($alpha, $acct, 'c-month', 100.0, Carbon::parse('2026-09-01')->addDays($i), 'USD');
        }
        $this->spend($alpha, $acct, 'c-month', 5000.0, Carbon::parse('2026-08-31'), 'USD');

        $web = ReportPeriod::resolve('monthly', $from, $to->copy()->startOfDay(), Carbon::parse('2026-11-03'));
        $webPrevious = app(MetricsAggregator::class)->forProjects([(string) $alpha->id])
            ->totals($web['previous_from']->copy()->startOfDay(), $web['previous_to']->copy()->endOfDay());

        $digest = app(DailyDigest::class)->buildRange($this->user, (string) $this->tenant->id, [(string) $alpha->id], $from, $to, 'monthly');

        $this->assertEquals(3000.0, $webPrevious['spend']);
        $this->assertEquals($webPrevious['spend'], $digest['projects'][0]['previous']['spend'], 'the monthly email compared against a different window than the monthly report');
        $this->assertEquals('2026-09-01', $digest['previous_date']);
    }

    public function test_sar_and_usd_projects_state_their_own_unit_and_the_mixed_portfolio_states_none(): void
    {
        $riyal = $this->project('Riyal');
        $dollar = $this->project('Dollar');
        $day = Carbon::today()->subDay();
        $a = $this->account('act-sar');
        $b = $this->account('act-usd');
        $this->bind($riyal, $a, true);
        $this->bind($dollar, $b, true);
        $this->spend($riyal, $a, 'c-sar', 3750.0, $day, 'SAR');
        $this->spend($dollar, $b, 'c-usd', 1000.0, $day, 'USD');
        $ids = [(string) $riyal->id, (string) $dollar->id];
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $web = app(MetricsAggregator::class)->acrossProjects();
        $digest = app(DailyDigest::class)->build($this->user, (string) $this->tenant->id, $ids, $day);
        $byName = [];
        foreach ($digest['projects'] as $block) {
            $byName[$block['project_name']] = $block;
        }

        // Each project: the web's figure and the web's unit.
        $this->assertEquals($web->forProjects([(string) $riyal->id])->totals($from, $to)['spend'], $byName['Riyal']['totals']['spend']);
        $this->assertSame($web->forProjects([(string) $riyal->id])->currencyBasis($from, $to)['currency'], $byName['Riyal']['currency']);
        $this->assertSame('SAR', $byName['Riyal']['currency']);
        $this->assertSame('USD', $byName['Dollar']['currency']);

        // The portfolio: the web overview's sum, and the web overview's «no single unit» (null).
        $overview = $web->forProjects($ids);
        $this->assertEquals($overview->totals($from, $to)['spend'], $digest['totals']['spend']);
        $this->assertNull($overview->currencyBasis($from, $to)['currency']);
        $this->assertNull($digest['currency'], 'a portfolio over two currencies must state no unit, as the web overview does');

        // And the inbox prints exactly that: SAR beside the SAR project, USD beside the USD one, no unit on the mixed total.
        $html = (new DailyDigestMail($digest, 'en', 'Reader'))->render();
        $this->assertStringContainsString('3,750 ر.س', $html);
        $this->assertStringContainsString('1,000 $', $html);
        $this->assertStringNotContainsString('4,750 $', $html);
        $this->assertStringNotContainsString('4,750 ر.س', $html);
        $this->assertStringContainsString('4,750', $html);
        $subject = (new DailyDigestMail($digest, 'en', 'Reader'))->envelope()->subject;
        $this->assertStringContainsString('4,750', (string) $subject);
        $this->assertStringNotContainsString('USD', (string) $subject);
        $this->assertStringNotContainsString('$', (string) $subject);
    }

    public function test_a_single_sar_project_is_printed_in_sar_not_the_reporting_default(): void
    {
        $riyal = $this->project('Riyal');
        $day = Carbon::today()->subDay();
        $a = $this->account('act-sar-only');
        $this->bind($riyal, $a, true);
        $this->spend($riyal, $a, 'c-sar-only', 12400.0, $day, 'SAR');

        $digest = app(DailyDigest::class)->build($this->user, (string) $this->tenant->id, [(string) $riyal->id], $day);
        $web = app(MetricsAggregator::class)->forProjects([(string) $riyal->id])->currencyBasis($day->copy()->startOfDay(), $day->copy()->endOfDay());

        $this->assertSame($web['currency'], $digest['currency']);
        $en = (new DailyDigestMail($digest, 'en', 'Reader'));
        $this->assertStringContainsString('12,400 ر.س', (string) $en->envelope()->subject);
        $this->assertStringContainsString('Currency: SAR', $en->render());
        $this->assertStringNotContainsString('12,400 $', $en->render());
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    private function project(string $name): Project
    {
        return Project::create(['client_workspace_id' => $this->client->id, 'name' => $name, 'status' => 'active']);
    }

    private function account(string $externalId): string
    {
        static $connection = null;
        if ($connection === null || $connection->tenant_id !== $this->tenant->id) {
            $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
            $credential->setPayload('token');
            $credential->save();
            $connection = ProviderConnection::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta',
                'connection_name' => 'c-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
            ]);
        }

        return (string) ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active',
        ])->id;
    }

    private function bind(Project $project, string $accountId, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $accountId,
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => $active, 'campaign_management_enabled' => true,
        ]);
    }

    private function spend(Project $project, string $accountId, string $campaign, float $amount, Carbon $day, string $currency): void
    {
        $row = static fn (string $key, float $value) => new NormalizedMetric(
            tenantId: (string) $project->tenant_id, projectId: (string) $project->id, provider: 'meta',
            externalAccountId: $accountId,
            externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, $campaign)->toString(),
            metricKey: $key, metricDate: $day->copy()->startOfDay(), value: $value,
            originalCurrency: $key === 'spend' ? $currency : null, projectCurrency: $currency,
            originalAmount: $key === 'spend' ? $amount : null,
        );
        app(UpsertDailyMetrics::class)->handle([$row('spend', $amount), $row('conversions', 5.0)]);
    }
}
