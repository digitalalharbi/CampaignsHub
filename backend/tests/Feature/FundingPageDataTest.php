<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Funding\Models\FundingMonthSummary;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingMonth;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * FIN-LOOP-001 part 3 — what the budgets page reads: the four totals, the remaining day by day with
 * its renewal steps, the fixed monthly summary that a later restatement never changes, and the export.
 */
final class FundingPageDataTest extends TestCase
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
        $user = User::create(['name' => 'Finance '.uniqid(), 'email' => 'u-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function project(string $client): Project
    {
        $c = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $client, 'slug' => Str::slug($client).'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $c->id, 'name' => $client.' launch', 'status' => 'active']);
    }

    private function spend(Project $project, string $date, float $amount, string $provider = 'snapchat'): void
    {
        DailyMetric::query()->updateOrCreate(
            ['project_id' => $project->id, 'external_account_id' => $this->account($project, $provider), 'external_campaign_id' => $this->account($project, $provider),
                'metric_key' => 'spend', 'metric_date' => $date, 'attribution_window' => 'default'],
            ['provider' => $provider, 'value' => $amount, 'original_amount' => $amount, 'original_currency' => 'SAR', 'project_currency' => 'SAR'],
        );
    }

    private function account(Project $project, string $provider): string
    {
        return Uuid::uuid5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $project->id.$provider)->toString();
    }

    /** @param array<string, mixed> $extra */
    private function add(Project $project, float $amount, string $date, array $extra = [])
    {
        return $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/payments', $extra + [
            'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'amount' => $amount, 'paid_on' => $date, 'reference' => 'TRX-'.$date, 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
    }

    public function test_the_tiles_add_up_the_prepaid_budgets_and_count_what_needs_a_decision(): void
    {
        $a = $this->project('Alpha');
        $b = $this->project('Beta');
        $this->add($a, 10_000, '2026-11-01');
        $this->add($b, 3_000, '2026-11-01');
        $this->spend($a, '2026-11-05', 4_000);
        $this->spend($b, '2026-11-05', 2_000);   // 1,000 left: under the line

        $res = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding')->assertOk();
        // The list reads the stored spend days; the sweep fills them.
        $this->artisan('funding:refresh')->assertSuccessful();
        $this->holdingTenant((string) $this->tenant->id);
        $res = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding')->assertOk();

        $this->assertSame(13_000.0, (float) $res->json('meta.totals.approved'));
        $this->assertSame(6_000.0, (float) $res->json('meta.totals.spent'));
        $this->assertSame(7_000.0, (float) $res->json('meta.totals.remaining'));
        $this->assertSame(1, $res->json('meta.totals.needs_attention'));
        $this->assertSame('Beta', $res->json('data.0.client_name'), 'the one that needs a decision comes first');
        $this->assertTrue($res->json('meta.can_manage'));
    }

    public function test_the_remaining_line_steps_up_on_a_renewal_and_slopes_down_with_spend(): void
    {
        $p = $this->project('Alpha');
        $this->add($p, 5_000, '2026-11-01');
        $this->spend($p, '2026-11-03', 1_000);
        $this->add($p, 2_000, '2026-11-05');

        $series = collect($this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$p->id)->assertOk()->json('data.series'))->keyBy('date');
        $this->assertSame(5_000.0, (float) $series['2026-11-02']['remaining']);
        $this->assertSame(4_000.0, (float) $series['2026-11-03']['remaining']);
        $this->assertSame(6_000.0, (float) $series['2026-11-05']['remaining']);
        $this->assertSame(2_000.0, (float) $series['2026-11-05']['added']);
        $this->assertSame('2026-11-13', $series->keys()->last());
    }

    public function test_a_closed_month_is_fixed_once_and_a_later_restatement_does_not_move_it(): void
    {
        $p = $this->project('Alpha');
        $this->add($p, 10_000, '2026-10-01');
        $this->add($p, 4_000, '2026-10-20', ['reference' => 'TRX-OCT-2']);
        $this->spend($p, '2026-10-10', 3_000);
        $this->spend($p, '2026-10-12', 1_000, 'tiktok');
        $this->spend($p, '2026-11-02', 500);

        $this->artisan('funding:close-month')->assertSuccessful();
        $this->holdingTenant((string) $this->tenant->id);
        $this->assertSame(0, FundingMonthSummary::query()->count(), 'not before the month has settled');

        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00', 'Asia/Riyadh'));
        $this->artisan('funding:close-month')->assertSuccessful();
        $this->artisan('funding:close-month')->assertSuccessful();
        $this->holdingTenant((string) $this->tenant->id);
        $this->assertSame(1, FundingMonthSummary::query()->count(), 'fixed once');

        $this->spend($p, '2026-10-10', 9_999);   // the provider restates October later
        $this->artisan('funding:refresh')->assertSuccessful();
        $this->holdingTenant((string) $this->tenant->id);

        $oct = $this->actingAs($this->finance, 'sanctum')->getJson("/api/v1/funding/projects/{$p->id}/months/2026-10")->assertOk()->json('data');
        $this->assertTrue($oct['fixed']);
        $this->assertSame(14_000.0, (float) $oct['added']);
        $this->assertSame(4_000.0, (float) $oct['spent']);
        $this->assertSame(10_000.0, (float) $oct['remaining_end']);
        $this->assertSame(['snapchat', 'tiktok'], array_column($oct['by_platform'], 'provider'));
        $this->assertSame('TRX-OCT-2', $oct['movements'][1]['reference']);

        $nov = $this->actingAs($this->finance, 'sanctum')->getJson("/api/v1/funding/projects/{$p->id}/months/2026-11")->assertOk()->json('data');
        $this->assertFalse($nov['fixed'], 'the current month is live, and says so');
        $this->assertSame(500.0, (float) $nov['spent']);
        $this->assertFalse(app(FundingMonth::class)->freeze(ProjectFunding::query()->firstOrFail(), '2026-11'), 'an open month is never fixed');

        $this->expectException(LogicException::class);
        FundingMonthSummary::query()->firstOrFail()->update(['figures' => []]);
    }

    public function test_the_export_carries_the_figures_and_references_for_the_reader_only(): void
    {
        $mine = $this->project('Mine');
        $theirs = $this->project('Theirs');
        $this->add($mine, 10_000, '2026-11-01', ['reference' => '=HYPERLINK("x")']);
        $this->add($theirs, 7_000, '2026-11-01');
        $this->spend($mine, '2026-11-04', 2_500);

        $xlsx = $this->actingAs($this->finance, 'sanctum')->get('/api/v1/funding/export?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $xlsx->getContent());
        $book = IOFactory::load($tmp);
        @unlink($tmp);
        $summary = $book->getSheetByName('summary')->toArray();
        $movements = $book->getSheetByName('movements')->toArray();
        $this->assertSame(2, count(array_filter($summary, static fn ($r) => in_array($r[0], ['Mine', 'Theirs'], true))));
        $ref = $book->getSheetByName('movements')->getCell('J'.(1 + (int) array_search('Mine', array_column($movements, 1), true)));
        $this->assertSame('=HYPERLINK("x")', $ref->getValue(), 'the reference is kept as typed');
        $this->assertSame(DataType::TYPE_STRING, $ref->getDataType(), 'and stored as text, never a formula');

        $csv = $this->actingAs($this->finance, 'sanctum')->get('/api/v1/funding/export?format=csv&client_id='.$mine->client_workspace_id)->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", (string) $csv);
        $this->assertStringContainsString("'=HYPERLINK", (string) $csv, 'in CSV the formula is prefixed');
        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/export?format=csv&from=%2B1%20week')->assertUnprocessable();
        $this->assertStringContainsString('Mine', (string) $csv);
        $this->assertStringNotContainsString('Theirs', (string) $csv);

        $scoped = $this->user(['funding.view', 'clients.view']);
        DB::table('client_workspace_user')->insert(['client_workspace_id' => $mine->client_workspace_id, 'user_id' => $scoped->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->holdingTenant((string) $this->tenant->id);
        $own = $this->actingAs($scoped, 'sanctum')->get('/api/v1/funding/export?format=csv&sheet=summary')->assertOk()->getContent();
        $this->assertStringContainsString('Mine', (string) $own);
        $this->assertStringNotContainsString('Theirs', (string) $own);

        $this->assertSame(3, AuditLog::query()->where('action', 'funding.exported')->count());
    }
}
