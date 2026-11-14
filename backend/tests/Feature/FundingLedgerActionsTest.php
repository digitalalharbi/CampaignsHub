<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingPdf;
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
 * BUDGET-LEDGER-001 — edit, delete and undo of a budget are ENTRIES: the ledger is never mutated, each action says
 * who, when and why, and only the latest action can be undone. Fixtures are synthetic.
 */
final class FundingLedgerActionsTest extends TestCase
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

    private function act(Project $p, string $action, array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->finance, 'sanctum')->postJson('/api/v1/funding/projects/'.$p->id.'/'.$action, $body);
    }

    private function entries(Project $p): int
    {
        return FundingPayment::query()->where('project_id', $p->id)->count();
    }

    public function test_edit_is_an_adjustment_entry_with_the_remaining_recalculated_and_an_audit_row(): void
    {
        $p = $this->project();
        $this->spend($p, (string) Str::uuid(), 3, 1_000, 'SAR');
        $this->add($p, 10_000)->assertCreated();

        $r = $this->act($p, 'adjust', ['amount' => 12_000, 'note' => 'تعديل بعد المراجعة'])->assertOk()->json('data');
        $this->assertEqualsWithDelta(12_000.0, (float) $r['approved'], 0.01);
        $this->assertEqualsWithDelta(11_000.0, (float) $r['remaining'], 0.01);
        $this->assertSame(2, $this->entries($p), 'the first amount is untouched; the edit is a second entry');

        $entry = FundingPayment::query()->findOrFail($r['entry_id']);
        $this->assertSame('adjustment', $entry->kind);
        $this->assertSame('2000.00', $entry->amount_sar);
        $this->assertSame('10000.00', FundingPayment::query()->where('kind', 'initial')->firstOrFail()->amount_sar);

        $audit = AuditLog::query()->where('action', 'funding.adjusted')->firstOrFail();
        $this->assertSame($this->finance->id, $audit->user_id, 'who');
        $this->assertSame('تعديل بعد المراجعة', $audit->reason, 'why');
        $this->assertNotNull($audit->created_at, 'when');

        $this->act($p, 'adjust', [])->assertUnprocessable();
        $this->act($p, 'adjust', ['period_start' => $this->day(2), 'period_end' => $this->day(5)])->assertUnprocessable()->assertJsonValidationErrors('period_end');
    }

    public function test_delete_needs_a_reason_hides_the_budget_and_keeps_every_entry(): void
    {
        $p = $this->project();
        $this->add($p, 10_000)->assertCreated();
        $this->act($p, 'void', [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $r = $this->act($p, 'void', ['reason' => 'أُضيفت بالخطأ'])->assertOk()->json('data');
        $this->assertSame('void', $r['status']);
        $this->assertEqualsWithDelta(0.0, (float) $r['approved'], 0.01);
        $this->assertSame(2, $this->entries($p), 'history kept: the amount and the delete');

        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.deleted_count', 1);
        $gone = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding?deleted=1')->assertOk();
        $gone->assertJsonCount(1, 'data')->assertJsonPath('data.0.void_reason', 'أُضيفت بالخطأ');
        $this->assertSame(0.0, (float) $gone->json('meta.totals.approved'), 'a deleted budget is in no total');
        $this->assertSame(1, AuditLog::query()->where('action', 'funding.voided')->count());

        $this->act($p, 'void', ['reason' => 'مرة ثانية'])->assertUnprocessable();
        $this->act($p, 'adjust', ['amount' => 5])->assertUnprocessable();

        // A new amount for the same project starts a fresh budget; the old entries stay.
        $this->add($p, 3_000)->assertCreated();
        $f = $this->figures($p);
        $this->assertEqualsWithDelta(3_000.0, (float) $f['approved'], 0.01);
        $this->assertNotSame('void', $f['status']);
        $this->assertSame(3, $this->entries($p));
    }

    public function test_undo_reverses_only_the_latest_action_and_restores_what_it_moved(): void
    {
        $p = $this->project();
        $this->add($p, 10_000)->assertCreated();
        $this->act($p, 'adjust', ['amount' => 15_000, 'period_start' => $this->day(9), 'period_end' => $this->day(1)])->assertOk();
        $this->assertSame($this->day(9), $this->figures($p)['period_start']);

        $detail = $this->figures($p);
        $latest = $detail['undo_entry_id'];
        $this->assertSame($latest, $detail['history'][0]['id']);
        $this->assertSame([true, false], array_map(static fn ($h): bool => $h['can_undo'], $detail['history']), 'undo is offered on the latest entry only');

        // A stale button must not undo something else.
        $this->act($p, 'undo', ['entry_id' => (string) Str::uuid()])->assertStatus(409);

        $r = $this->act($p, 'undo', ['entry_id' => $latest])->assertOk()->json('data');
        $this->assertEqualsWithDelta(10_000.0, (float) $r['approved'], 0.01);
        $this->assertNull($r['period_end'], 'the period is put back with the amount');
        $this->assertSame(3, $this->entries($p), 'the undo is itself an entry, not an erasure');
        $reversal = FundingPayment::query()->findOrFail($r['entry_id']);
        $this->assertSame('reversal', $reversal->kind);
        $this->assertSame($latest, $reversal->reverses_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'funding.undone')->count());

        // The reversal is not undoable; the entry before it now is (stack order).
        $afterUndo = $this->figures($p);
        $this->assertSame('initial', collect($afterUndo['history'])->firstWhere('id', $afterUndo['undo_entry_id'])['kind'], 'the reversal is not undoable; the entry before the undone one is');
        $second = $this->act($p, 'undo')->assertOk()->json('data');
        $this->assertSame('void', $second['status'], 'undoing the entry that started the budget removes it from view');
        $this->act($p, 'undo')->assertUnprocessable();
    }

    public function test_undo_of_a_delete_brings_the_budget_back(): void
    {
        $p = $this->project();
        $this->add($p, 8_000)->assertCreated();
        $this->act($p, 'void', ['reason' => 'اختبار'])->assertOk();

        $r = $this->act($p, 'undo')->assertOk()->json('data');
        $this->assertEqualsWithDelta(8_000.0, (float) $r['approved'], 0.01);
        $this->assertNull(ProjectFunding::query()->firstOrFail()->voided_at);
        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding')->assertJsonCount(1, 'data');
    }

    public function test_undo_of_a_renewal_takes_the_renewal_out_only(): void
    {
        $p = $this->project();
        $this->add($p, 5_000, 3)->assertCreated();
        $this->add($p, 2_000)->assertCreated();
        $r = $this->act($p, 'undo')->assertOk()->json('data');
        $this->assertEqualsWithDelta(5_000.0, (float) $r['approved'], 0.01);
        $this->assertNotSame('void', $r['status']);
    }

    public function test_the_ledger_is_never_updated_or_deleted_by_any_action(): void
    {
        $p = $this->project();
        $this->add($p, 5_000)->assertCreated();
        $before = FundingPayment::query()->firstOrFail()->only(['amount', 'amount_sar', 'paid_on', 'kind', 'created_by']);
        $this->act($p, 'adjust', ['amount' => 6_000])->assertOk();
        $this->act($p, 'undo')->assertOk();
        $this->act($p, 'void', ['reason' => 'x1'])->assertOk();
        $this->act($p, 'undo')->assertOk();
        $this->assertEquals($before, FundingPayment::query()->where('kind', 'initial')->firstOrFail()->only(['amount', 'amount_sar', 'paid_on', 'kind', 'created_by']));
        $this->assertSame(5, $this->entries($p));
    }

    public function test_only_finance_edits_and_a_stranger_finds_nothing(): void
    {
        $p = $this->project();
        $this->add($p, 5_000)->assertCreated();

        $reader = $this->user(['funding.view', 'clients.view', 'clients.view_all', 'projects.view.all']);
        $this->holdingTenant((string) $this->tenant->id);
        foreach ([['adjust', ['amount' => 9]], ['void', ['reason' => 'ab']], ['undo', []]] as [$action, $body]) {
            $this->act($p, $action, $body, $reader)->assertForbidden();
        }
        $this->assertSame(1, $this->entries($p), 'a refused request changes nothing');

        // Another tenant's finance: the project does not exist for them.
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active', 'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId((string) $other->id);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::query()->whereIn('key', ['funding.manage', 'funding.view', 'clients.view', 'clients.view_all', 'projects.view.all'])->pluck('key')->all());
        $stranger = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@b.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($stranger, $other);
        $stranger->assignRole($role);
        $this->holdingTenant((string) $other->id);
        foreach ([['adjust', ['amount' => 9]], ['void', ['reason' => 'ab']], ['undo', []]] as [$action, $body]) {
            $this->act($p, $action, $body, $stranger)->assertNotFound();
        }
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $this->assertSame(1, $this->entries($p));
    }

    public function test_a_member_confined_to_other_clients_cannot_edit_this_budget(): void
    {
        $p = $this->project();
        $this->add($p, 5_000)->assertCreated();
        $confined = $this->user(['funding.manage', 'funding.view', 'clients.view']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->act($p, 'adjust', ['amount' => 9], $confined)->assertNotFound();
        $this->act($p, 'void', ['reason' => 'ab'], $confined)->assertNotFound();
        $this->act($p, 'undo', [], $confined)->assertNotFound();
        $this->assertSame(1, $this->entries($p));
    }

    public function test_the_pdf_carries_the_same_figures_as_the_sheet(): void
    {
        $p = $this->project();
        $a = (string) Str::uuid();
        $this->spend($p, $a, 3, 1_000, 'SAR', 'snapchat');
        $this->spend($p, (string) Str::uuid(), 3, 100, 'USD', 'meta');
        $this->add($p, 10_000)->assertCreated();
        $this->act($p, 'adjust', ['amount' => 12_000, 'note' => 'زيادة'])->assertOk();

        $csv = (string) $this->actingAs($this->finance, 'sanctum')->get('/api/v1/funding/export?format=csv&sheet=summary')->assertOk()->getContent();
        $this->assertStringContainsString('12000', $csv);
        $this->assertStringContainsString('snapchat:1000.00; meta:375.00', $csv, 'the per-platform split is in the sheet too');

        $figures = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$p->id)->assertOk()->json('data');
        $row = ['client' => 'عميل', 'project' => $p->name] + $figures;
        $html = app(FundingPdf::class)->html([$row], [(string) $p->id => FundingPayment::query()->where('project_id', $p->id)->orderBy('seq')->get()->all()], '2026-11-13');
        $this->assertStringContainsString('ملخص الميزانيات', $html);
        $this->assertStringContainsString('12,000', $html);
        $this->assertStringContainsString('10,625', $html, 'remaining = 12,000 - 1,375');
        $this->assertStringContainsString('سناب شات', $html);
        $this->assertStringContainsString('زيادة', $html, 'the history reason is printed');
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    /** The real file: needs Chromium and playwright-core, which the backend CI job does not have (as for the invoice PDF). */
    public function test_the_pdf_is_a_real_file_when_the_renderer_is_available(): void
    {
        if (! config('reports.chromium.enabled') || ! is_dir(base_path('../frontend/node_modules/playwright-core'))) {
            $this->markTestSkipped('Chromium PDF rendering is not available in this environment (not enabled, or playwright-core is not installed).');
        }

        $p = $this->project();
        $this->spend($p, (string) Str::uuid(), 3, 1_000, 'SAR', 'snapchat');
        $this->add($p, 10_000)->assertCreated();
        $this->act($p, 'adjust', ['amount' => 12_000, 'note' => 'زيادة'])->assertOk();

        $res = $this->actingAs($this->finance, 'sanctum')->get('/api/v1/funding/export?format=pdf&history=1')->assertOk();
        $bytes = (string) $res->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(1, AuditLog::query()->where('action', 'funding.exported')->where('after->format', 'pdf')->count());
        // The Arabic text layer is repaired by the report pipeline's fail-closed pass (it throws, and so no file is
        // served, when presentation forms remain); the file here is therefore real text, checked by hand with PDFKit.
    }
}
