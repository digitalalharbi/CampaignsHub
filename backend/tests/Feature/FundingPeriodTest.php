<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * BUDGET-PERIOD-001 — a budget counts the spend of its PERIOD, and a period that is not stated starts where the
 * project's real spend started. The Owner's case: a 50,000 SAR budget added a week after the campaign began,
 * which had already spent about 2,000 USD, must not look untouched.
 *
 * Fixtures are synthetic: no client or project of Production appears here.
 */
final class FundingPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00', 'Asia/Riyadh'));
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        $this->finance = $this->user(['funding.manage', 'funding.view', 'clients.view', 'clients.view_all', 'projects.view.all']);
        $this->holdingTenant((string) $this->tenant->id);
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-01-01', 'source' => 'peg']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param list<string> $permissions */
    private function user(array $permissions): User
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::query()->whereIn('key', $permissions)->pluck('key')->all());
        $user = User::create(['name' => 'U '.uniqid(), 'email' => 'u-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function project(): Project
    {
        $c = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Client '.uniqid(), 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $c->id, 'name' => 'Project '.uniqid(), 'status' => 'active']);
    }

    private function day(int $ago): string
    {
        return Carbon::today('Asia/Riyadh')->subDays($ago)->toDateString();
    }

    private function spend(Project $p, string $account, int $ago, float $amount, string $currency, string $provider = 'snapchat'): void
    {
        DailyMetric::query()->updateOrCreate(
            ['project_id' => $p->id, 'external_account_id' => $account, 'external_campaign_id' => Uuid::uuid5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $provider.$account)->toString(),
                'metric_key' => 'spend', 'metric_date' => $this->day($ago), 'attribution_window' => 'default'],
            ['provider' => $provider, 'value' => $amount, 'original_amount' => $amount, 'original_currency' => $currency, 'project_currency' => 'SAR'],
        );
    }

    private function add(Project $p, float $amount, int $ago = 0, array $extra = [])
    {
        return $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/payments', $extra + [
            'client_workspace_id' => $p->client_workspace_id, 'project_id' => $p->id, 'amount' => $amount,
            'paid_on' => $this->day($ago), 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /** @return array<string, mixed> */
    private function figures(Project $p): array
    {
        return (array) $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$p->id)->assertOk()->json('data');
    }

    /** The Owner's case: 2,000 USD spent in the week BEFORE the 50,000 SAR budget was added is 7,500 SAR, not nothing. */
    public function test_spend_before_the_budget_was_added_counts_from_the_first_day_of_spend(): void
    {
        $p = $this->project();
        $snap = (string) Str::uuid();
        $meta = (string) Str::uuid();
        foreach ([7, 6, 5, 4] as $ago) {
            $this->spend($p, $snap, $ago, 250, 'USD', 'snapchat');   // 1,000 USD
            $this->spend($p, $meta, $ago, 250, 'USD', 'meta');       // 1,000 USD
        }
        $this->add($p, 50_000, 0)->assertCreated();

        $f = $this->figures($p);
        $this->assertSame($this->day(7), $f['period_start'], 'the period starts on the first day of spend, not the day the budget was added');
        $this->assertNull($f['period_end']);
        $this->assertEqualsWithDelta(7_500.0, (float) $f['spent'], 0.01);
        $this->assertEqualsWithDelta(42_500.0, (float) $f['remaining'], 0.01);
        $this->assertEqualsWithDelta(0.15, (float) $f['used_ratio'], 0.0001);

        $split = collect($f['by_platform'])->pluck('spent', 'provider');
        $this->assertEqualsWithDelta(3_750.0, (float) $split['snapchat'], 0.01);
        $this->assertEqualsWithDelta(3_750.0, (float) $split['meta'], 0.01);
    }

    public function test_an_explicit_period_counts_only_its_own_days(): void
    {
        $p = $this->project();
        $a = (string) Str::uuid();
        $this->spend($p, $a, 20, 1_000, 'SAR');   // before
        $this->spend($p, $a, 10, 400, 'SAR');     // inside
        $this->spend($p, $a, 8, 100, 'USD');      // inside: 375
        $this->spend($p, $a, 2, 5_000, 'SAR');    // after the period
        $this->add($p, 10_000, 0)->assertCreated();

        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/projects/'.$p->id.'/adjust', [
            'period_start' => $this->day(12), 'period_end' => $this->day(5),
        ])->assertOk();

        $f = $this->figures($p);
        $this->assertSame($this->day(12), $f['period_start']);
        $this->assertSame($this->day(5), $f['period_end']);
        $this->assertEqualsWithDelta(775.0, (float) $f['spent'], 0.01);
        $this->assertEqualsWithDelta(9_225.0, (float) $f['remaining'], 0.01);
    }

    public function test_spend_in_a_currency_without_a_rate_is_said_not_guessed(): void
    {
        $p = $this->project();
        $a = (string) Str::uuid();
        $this->spend($p, $a, 3, 100, 'SAR');
        $this->spend($p, (string) Str::uuid(), 3, 50, 'EUR', 'tiktok');
        $this->add($p, 1_000, 0)->assertCreated();

        $f = $this->figures($p);
        $this->assertSame(100.0, (float) $f['spent']);
        $this->assertSame(1, $f['spend_without_rate']);
        $this->assertSame('incomplete', $f['status']);
        $tiktok = collect($f['by_platform'])->firstWhere('provider', 'tiktok');
        $this->assertSame(1, $tiktok['unconverted_days'], 'the platform with no rate says so in its own row');
    }

    /** opening + added − spend = closing, and one month closes at exactly what the next one opens with. */
    public function test_the_monthly_summary_stays_consistent_with_the_period(): void
    {
        $p = $this->project();
        $a = (string) Str::uuid();
        $this->spend($p, $a, 20, 1_000, 'SAR');      // 2026-10-24
        $this->spend($p, $a, 3, 2_000, 'SAR');       // 2026-11-10
        $this->add($p, 10_000, 0)->assertCreated();   // 2026-11-13

        $oct = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$p->id.'/months/2026-10')->assertOk()->json('data');
        $nov = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$p->id.'/months/2026-11')->assertOk()->json('data');

        $this->assertEqualsWithDelta(-1_000.0, (float) $oct['remaining_end'], 0.01, 'October had spend and no budget yet');
        $this->assertEqualsWithDelta((float) $oct['remaining_end'], (float) $nov['remaining_start'], 0.01);
        $this->assertEqualsWithDelta(
            (float) $nov['remaining_start'] + (float) $nov['added'] - (float) $nov['spent'],
            (float) $nov['remaining_end'],
            0.01,
        );
        $this->assertEqualsWithDelta(7_000.0, (float) $nov['remaining_end'], 0.01);
    }
}
