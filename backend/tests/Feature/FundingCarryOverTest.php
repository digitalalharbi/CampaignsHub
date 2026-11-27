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
 * BUDGET-CARRY-001 — «ترحيل للشهر الجديد». Renewing a budget whose period has ENDED used to add the money but keep the old
 * end, so the new month's spend was never counted against it (the Owner: «جدّد الميزانية غير فعال»). A renewal may now
 * move the end forward: what was left carries into the new month, the entry records the carry and both ends, and undo
 * puts the old end back.
 *
 * Fixtures are synthetic: no client or project of Production appears here.
 */
final class FundingCarryOverTest extends TestCase
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

    private function add(Project $p, float $amount, array $extra = [])
    {
        return $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/payments', $extra + [
            'client_workspace_id' => $p->client_workspace_id, 'project_id' => $p->id, 'amount' => $amount,
            'paid_on' => $this->day(0), 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /** @return array<string, mixed> */
    private function figures(Project $p): array
    {
        return (array) $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$p->id)->assertOk()->json('data');
    }

    /** An October budget, 4,000 spent in October, 1,000 spent in November outside its period. */
    private function octoberBudget(): Project
    {
        $p = $this->project();
        $a = (string) Str::uuid();
        $this->spend($p, $a, 34, 4_000, 'SAR');   // 2026-10-10
        $this->spend($p, $a, 8, 1_000, 'SAR');    // 2026-11-05
        $this->add($p, 10_000, ['period_start' => '2026-10-01', 'period_end' => '2026-10-31'])->assertCreated();
        $f = $this->figures($p);
        $this->assertEqualsWithDelta(6_000.0, (float) $f['remaining'], 0.01, 'the November spend is outside the October period');

        return $p;
    }

    public function test_a_renewal_carries_the_remaining_into_the_new_month(): void
    {
        $p = $this->octoberBudget();

        $this->add($p, 5_000, ['extend_to' => '2026-11-30'])->assertCreated();

        $f = $this->figures($p);
        $this->assertSame('2026-11-30', $f['period_end']);
        $this->assertEqualsWithDelta(15_000.0, (float) $f['approved'], 0.01);
        $this->assertEqualsWithDelta(5_000.0, (float) $f['spent'], 0.01, 'November spend now counts');
        $this->assertEqualsWithDelta(10_000.0, (float) $f['remaining'], 0.01, '6,000 carried + 5,000 new − 1,000 November');

        $entry = collect($f['history'])->firstWhere('kind', 'top_up');
        $this->assertSame('2026-11-30', $entry['period_after']);
        $this->assertEqualsWithDelta(6_000.0, (float) $entry['carried'], 0.01);
        $this->assertNotEmpty($entry['added_at']);
    }

    public function test_undo_puts_the_old_end_back(): void
    {
        $p = $this->octoberBudget();
        $this->add($p, 5_000, ['extend_to' => '2026-11-30'])->assertCreated();

        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/projects/'.$p->id.'/undo', [])->assertSuccessful();

        $f = $this->figures($p);
        $this->assertSame('2026-10-31', $f['period_end']);
        $this->assertEqualsWithDelta(10_000.0, (float) $f['approved'], 0.01);
        $this->assertEqualsWithDelta(6_000.0, (float) $f['remaining'], 0.01);
    }

    public function test_without_extend_to_the_period_is_kept(): void
    {
        $p = $this->octoberBudget();
        $this->add($p, 5_000)->assertCreated();

        $f = $this->figures($p);
        $this->assertSame('2026-10-31', $f['period_end']);
        $this->assertNull(collect($f['history'])->firstWhere('kind', 'top_up')['carried']);
    }

    public function test_extend_to_never_moves_the_end_backwards(): void
    {
        $p = $this->octoberBudget();
        $this->add($p, 5_000, ['extend_to' => '2026-10-15'])->assertCreated();

        $this->assertSame('2026-10-31', $this->figures($p)['period_end']);
    }

    public function test_an_open_period_has_nothing_to_move(): void
    {
        $p = $this->project();
        $this->add($p, 10_000)->assertCreated();
        $this->add($p, 5_000, ['extend_to' => '2026-11-30'])->assertCreated();

        $this->assertNull($this->figures($p)['period_end']);
    }
}
