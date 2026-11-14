<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingSpendRefresher;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * FIN-LOOP-001 — finance adds a project budget in one step; real spend is deducted once per account-day;
 * a renewal adds without resetting anything. Gates F04, F05, F06, F07, F09, F11 (master v2.2 §3).
 *
 * The figures are the acceptance example of the master spec, not a Production balance.
 */
final class FundingLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = $this->tenant('A');
        $this->finance = $this->user($this->tenant, ['funding.manage', 'funding.view', 'clients.view', 'clients.view_all', 'clients.create', 'projects.view.all']);
        $this->holdingTenant((string) $this->tenant->id);
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
    }

    /** @param list<string> $permissions */
    private function user(Tenant $tenant, array $permissions): User
    {
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::query()->whereIn('key', $permissions)->pluck('key')->all());
        $user = User::create(['name' => 'U '.uniqid(), 'email' => 'u-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return $user;
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    private function project(ClientWorkspace $client, string $name = 'Launch'): Project
    {
        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => $name, 'status' => 'active']);
    }

    private function day(int $ago): string
    {
        return Carbon::today('Asia/Riyadh')->subDays($ago)->toDateString();
    }

    private function spend(Project $project, string $account, int $ago, float $amount, string $currency = 'SAR', string $campaign = 'c1', string $window = 'default'): void
    {
        DailyMetric::query()->updateOrCreate(
            ['project_id' => $project->id, 'external_account_id' => $account, 'external_campaign_id' => $this->uuid($campaign),
                'metric_key' => 'spend', 'metric_date' => $this->day($ago), 'attribution_window' => $window],
            ['provider' => 'snapchat', 'value' => $amount, 'original_amount' => $amount, 'original_currency' => $currency, 'project_currency' => 'SAR'],
        );
    }

    private function uuid(string $seed): string
    {
        return Uuid::uuid5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $seed)->toString();
    }

    /** @param array<string, mixed> $extra */
    private function add(Project $project, float $amount, int $ago, ?string $key = null, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->finance, 'sanctum')->postJson('/api/v1/funding/payments', $extra + [
            'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->id,
            'amount' => $amount,
            'paid_on' => $this->day($ago),
            'reference' => 'TRX-'.$ago,
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** @return array<string, mixed> */
    private function figures(Project $project, ?User $as = null): array
    {
        return (array) $this->actingAs($as ?? $this->finance, 'sanctum')
            ->getJson('/api/v1/funding/projects/'.$project->id)->assertOk()->json('data');
    }

    /** F05 — 41,250 − 30,711 = 10,539; a 20,000 renewal makes 61,250 approved and 30,539 remaining; spend unchanged. */
    public function test_the_acceptance_example_and_a_renewal_that_adds_without_resetting(): void
    {
        $project = $this->project($this->client('Club'));
        $account = (string) Str::uuid();

        $this->spend($project, $account, 45, 999);       // before the first amount: never counted
        $this->spend($project, $account, 35, 10_000);
        $this->spend($project, $account, 25, 15_000);
        $this->spend($project, $account, 5, 5_711);

        foreach ([[10_000, 40], [7_500, 30], [3_750, 20], [20_000, 10]] as [$amount, $ago]) {
            $this->add($project, $amount, $ago)->assertCreated();
        }

        // BUDGET-PERIOD-001: without a stated start the period begins at the first day of spend; this example states its own.
        $this->actingAs($this->finance, 'sanctum')->patchJson('/api/v1/funding/projects/'.$project->id, ['starts_on' => $this->day(40)])->assertOk();

        $f = $this->figures($project);
        $this->assertSame(41_250.0, (float) $f['approved']);
        $this->assertSame(30_711.0, (float) $f['spent']);
        $this->assertSame(10_539.0, (float) $f['remaining']);
        $this->assertSame('initial', collect($f['history'])->last()['kind']);

        $renewal = $this->add($project, 20_000, 0)->assertCreated()->json('data');
        $this->assertSame(61_250.0, (float) $renewal['approved']);
        $this->assertSame(30_539.0, (float) $renewal['remaining']);
        $this->assertSame(30_711.0, (float) $renewal['spent'], 'a renewal never touches spend');

        $last = FundingPayment::query()->where('project_id', $project->id)->latest('paid_on')->firstOrFail();
        $this->assertSame('top_up', $last->kind);
        $this->assertSame('10539.00', $last->remaining_before);
        $this->assertSame('30539.00', $last->remaining_after);
        $this->assertSame(5, FundingPayment::query()->where('project_id', $project->id)->count());
    }

    /** F06 — a retry or double click with the same key adds once; the record is append-only. */
    public function test_a_retried_renewal_is_counted_once_and_nothing_is_ever_edited_or_deleted(): void
    {
        $project = $this->project($this->client('Club'));
        $this->add($project, 10_000, 3)->assertCreated();

        $key = (string) Str::uuid();
        $this->add($project, 5_000, 1, $key)->assertCreated();
        $again = $this->add($project, 5_000, 1, $key)->assertOk()->json('data');

        $this->assertFalse($again['created']);
        $this->assertSame(15_000.0, (float) $again['approved']);
        $this->assertSame(2, FundingPayment::query()->where('project_id', $project->id)->count());

        $payment = FundingPayment::query()->firstOrFail();
        try {
            $payment->update(['amount' => 1]);
            $this->fail('an approved amount was edited');
        } catch (LogicException) {
        }
        try {
            $payment->delete();
            $this->fail('an approved amount was deleted');
        } catch (LogicException) {
        }
        $this->assertSame(2, FundingPayment::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'funding.payment.added')->count(), 'each real addition is audited once');
    }

    /** F07 — the same account-day after a resync or reconnect replaces its figure; it is never deducted twice. */
    public function test_a_resync_replaces_the_day_and_never_deducts_it_twice(): void
    {
        $project = $this->project($this->client('Club'));
        $account = (string) Str::uuid();
        $this->add($project, 10_000, 10)->assertCreated();

        $this->spend($project, $account, 2, 1_000);
        $this->spend($project, $account, 2, 1_000, window: '7d_click');  // same campaign-day under a second window
        $this->assertSame(1_000.0, (float) $this->figures($project)['spent']);
        $this->assertSame(1_000.0, (float) $this->figures($project)['spent'], 'reading twice changes nothing');

        $this->spend($project, $account, 2, 1_200);                      // the provider restated the day
        $this->spend($project, $account, 2, 1_200, window: '7d_click');
        $refresher = app(FundingSpendRefresher::class);
        $funding = ProjectFunding::query()->firstOrFail();
        $refresher->refresh($funding);
        $refresher->refresh($funding);

        $f = $this->figures($project);
        $this->assertSame(1_200.0, (float) $f['spent']);
        $this->assertSame(8_800.0, (float) $f['remaining']);
        $this->assertSame(1, DB::table('funding_spend_days')->where('project_id', $project->id)->count());
    }

    /** F09 — direct-pay projects carry no remaining; spend before the counted start is not deducted; a missing rate is never zero. */
    public function test_direct_pay_start_day_and_missing_rates_are_stated_not_guessed(): void
    {
        $project = $this->project($this->client('Club'));
        $account = (string) Str::uuid();
        $this->add($project, 10_000, 10)->assertCreated();
        $this->spend($project, $account, 12, 4_000);
        $this->spend($project, $account, 3, 500);
        $this->spend($project, (string) Str::uuid(), 2, 100, currency: 'EUR');
        $this->actingAs($this->finance, 'sanctum')->patchJson('/api/v1/funding/projects/'.$project->id, ['starts_on' => $this->day(10)])->assertOk();

        $f = $this->figures($project);
        $this->assertSame(500.0, (float) $f['spent'], 'day 12 is before the stated start; the EUR day has no rate');
        $this->assertSame(1, $f['spend_without_rate']);
        $this->assertFalse($f['complete']);

        $this->actingAs($this->finance, 'sanctum')->patchJson('/api/v1/funding/projects/'.$project->id, ['payment_method' => 'direct'])->assertOk();
        $f = $this->figures($project);
        $this->assertNull($f['remaining']);
        $this->assertSame('direct', $f['status']);

        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => $this->day(5), 'source' => 'test']);
        $usd = $this->add($project, 100, 1, extra: ['currency' => 'USD'])->assertCreated();
        $this->assertSame('375.00', FundingPayment::query()->findOrFail($usd->json('data.payment_id'))->amount_sar);
        $this->add($project, 100, 1, extra: ['currency' => 'GBP'])->assertUnprocessable()->assertJsonValidationErrors('currency');
    }

    /** F04 — a new client and a new project are created once, then reused by name; no duplicate either way. */
    public function test_a_new_client_and_project_are_created_once_without_waiting_for_a_project(): void
    {
        $body = ['client_name' => 'Fresh Client', 'project_name' => 'Winter push', 'amount' => 5_000, 'paid_on' => $this->day(0)];
        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/payments', $body + ['idempotency_key' => 'k1'])->assertCreated();
        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/payments', ['client_name' => ' fresh client ', 'project_name' => 'WINTER PUSH']
            + $body + ['idempotency_key' => 'k2'])->assertCreated();

        $this->assertSame(1, ClientWorkspace::query()->where('name', 'Fresh Client')->count());
        $this->assertSame(1, Project::query()->where('name', 'Winter push')->count());
        $this->assertSame(1, ProjectFunding::query()->count());
        $this->assertSame(10_000.0, (float) FundingPayment::query()->sum('amount_sar'));
        $this->assertSame(1, AuditLog::query()->where('action', 'project.created')->count());
    }

    /** F11 + roles — finance adds; a reader without it cannot; a member sees only their clients; another tenant sees nothing. */
    public function test_only_finance_adds_and_each_reader_sees_only_their_own_clients(): void
    {
        $mine = $this->project($this->client('Mine'));
        $theirs = $this->project($this->client('Theirs'));
        $this->add($mine, 1_000, 1)->assertCreated();
        $this->add($theirs, 2_000, 1)->assertCreated();

        $campaigns = $this->user($this->tenant, ['budget.view', 'clients.view', 'projects.view']);
        DB::table('client_workspace_user')->insert(['client_workspace_id' => $mine->client_workspace_id, 'user_id' => $campaigns->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->holdingTenant((string) $this->tenant->id);

        $this->add($mine, 500, 0, as: $campaigns)->assertForbidden();
        $this->actingAs($campaigns, 'sanctum')->getJson('/api/v1/funding/projects/'.$theirs->id)->assertNotFound();
        $names = collect($this->actingAs($campaigns, 'sanctum')->getJson('/api/v1/funding')->assertOk()->json('data'))->pluck('client_name')->all();
        $this->assertSame(['Mine'], $names);

        $nobody = $this->user($this->tenant, ['clients.view']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->actingAs($nobody, 'sanctum')->getJson('/api/v1/funding')->assertForbidden();

        $other = $this->tenant('B');
        $stranger = $this->user($other, ['funding.manage', 'funding.view', 'clients.view', 'clients.view_all', 'projects.view.all']);
        $this->holdingTenant((string) $other->id);
        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/funding/projects/'.$mine->id)->assertNotFound();
        $this->assertSame([], $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/funding')->assertOk()->json('data'));
        $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/funding/payments', ['client_workspace_id' => $mine->client_workspace_id,
            'project_id' => $mine->id, 'amount' => 1, 'paid_on' => $this->day(0), 'idempotency_key' => 'x'])->assertUnprocessable();
    }

    /** The pace and the days the remaining lasts come from real spend of the last 7 full days only. */
    public function test_pace_and_days_left_come_from_real_spend_only(): void
    {
        $project = $this->project($this->client('Club'));
        $account = (string) Str::uuid();
        $this->add($project, 10_000, 20)->assertCreated();
        $this->assertNull($this->figures($project)['pace_per_day'], 'no spend, no pace');

        foreach (range(1, 7) as $ago) {
            $this->spend($project, $account, $ago, 700);
        }
        $f = $this->figures($project);
        $this->assertSame(700.0, (float) $f['pace_per_day']);
        $this->assertSame(5_100.0, (float) $f['remaining']);
        $this->assertSame(7, $f['days_left']);
        $this->assertSame('ok', $f['status']);
    }

    /** A project membership that withholds money withholds the whole budget — refused, not half-stripped. */
    public function test_a_membership_that_withholds_money_sees_no_budget_of_that_project(): void
    {
        $project = $this->project($this->client('Club'));
        $this->add($project, 1_000, 1)->assertCreated();

        $member = $this->user($this->tenant, ['budget.view', 'clients.view', 'clients.view_all', 'projects.view']);
        ProjectMembership::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'user_id' => $member->id,
            'role' => 'viewer', 'status' => 'active', 'denied' => ['money.view']]);
        $this->holdingTenant((string) $this->tenant->id);

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/funding/projects/'.$project->id)->assertNotFound();
        $this->assertSame([], $this->actingAs($member, 'sanctum')->getJson('/api/v1/funding')->assertOk()->json('data'));

        // Holding `funding.manage` does not open it either, whether the project is named by id or by name.
        $member->roles()->first()?->givePermissionTo('funding.manage');
        $member->unsetRelation('roles');
        $this->add($project, 500, 0, as: $member)->assertNotFound();
        $this->add($project, 500, 0, extra: ['project_id' => null, 'project_name' => ' launch '], as: $member)->assertNotFound();
        $this->assertSame(1, FundingPayment::query()->count());
    }

    /** Spend of an account the project no longer selects is not read; two currencies on one day are converted apart. */
    public function test_unselected_accounts_and_mixed_currencies_are_not_mixed_into_the_budget(): void
    {
        $project = $this->project($this->client('Club'));
        $this->add($project, 10_000, 10)->assertCreated();
        $kept = (string) Str::uuid();
        $dropped = (string) Str::uuid();
        $this->spend($project, $kept, 2, 300);
        $this->spend($project, $dropped, 2, 900);
        DB::table('project_integration_bindings')->where('external_account_id', $dropped)->update(['is_active' => false]);

        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => $this->day(5), 'source' => 'test']);
        $this->spend($project, $kept, 1, 100, currency: 'USD', campaign: 'c-usd');
        $this->spend($project, $kept, 1, 50, campaign: 'c-sar');

        $f = $this->figures($project);
        $this->assertSame(300.0 + 375.0 + 50.0, (float) $f['spent'], 'USD 100 is 375 SAR, never 100 or 150 SAR');
        $this->assertTrue($f['complete']);
    }

    /** A remaining that leaves out unconverted spend never reads «ok»; the counted start cannot be pushed into the future. */
    public function test_an_incomplete_remaining_is_flagged_and_the_start_cannot_skip_ahead(): void
    {
        $project = $this->project($this->client('Club'));
        $this->add($project, 10_000, 10)->assertCreated();
        $this->spend($project, (string) Str::uuid(), 2, 100, currency: 'EUR');
        $this->assertSame('incomplete', $this->figures($project)['status']);

        $this->actingAs($this->finance, 'sanctum')->patchJson('/api/v1/funding/projects/'.$project->id,
            ['starts_on' => Carbon::today('Asia/Riyadh')->addDays(30)->toDateString()])->assertUnprocessable();
        $this->add($project, 1, 0, extra: ['paid_on' => Carbon::today('Asia/Riyadh')->addDay()->toDateString()])->assertUnprocessable();
    }
}
