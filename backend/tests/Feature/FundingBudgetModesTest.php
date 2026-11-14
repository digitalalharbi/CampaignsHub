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
use App\Domains\Notifications\Models\AppNotification;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * BUDGET-MODES-001 — a budget is direct (a prepaid balance) or on credit (spend recorded, invoiced later by the month), and may
 * carry a plan per platform. Fixtures are synthetic; «today» is 2026-11-13 (Riyadh).
 */
final class FundingBudgetModesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $finance;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00', 'Asia/Riyadh'));
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        $this->finance = $this->user(['funding.manage', 'funding.view', 'clients.view', 'clients.view_all', 'projects.view.all']);
        $this->manager = $this->user(['budget.view', 'clients.view', 'clients.view_all', 'projects.view', 'projects.view.all']);
        $this->holdingTenant((string) $this->tenant->id);
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-01-01', 'source' => 'peg']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param list<string> $permissions */
    private function user(array $permissions, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::query()->whereIn('key', $permissions)->pluck('key')->all());
        $user = User::create(['name' => 'U '.uniqid(), 'email' => 'u-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return $user;
    }

    private function project(): Project
    {
        $c = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Client '.uniqid(), 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $c->id, 'name' => 'Project '.uniqid(),
            'status' => 'active', 'account_manager_id' => $this->manager->id]);
    }

    private function day(int $ago): string
    {
        return Carbon::today('Asia/Riyadh')->subDays($ago)->toDateString();
    }

    private function spend(Project $p, int $ago, float $amount, string $currency = 'SAR', string $provider = 'snapchat', string $account = 'acc-1'): void
    {
        DailyMetric::query()->updateOrCreate(
            ['project_id' => $p->id, 'external_account_id' => Uuid::uuid5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'account'.$provider.$account.$p->id)->toString(), 'external_campaign_id' => Uuid::uuid5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $provider.$account)->toString(),
                'metric_key' => 'spend', 'metric_date' => $this->day($ago), 'attribution_window' => 'default'],
            ['provider' => $provider, 'value' => $amount, 'original_amount' => $amount, 'original_currency' => $currency, 'project_currency' => 'SAR'],
        );
    }

    private function add(Project $p, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->finance, 'sanctum')->postJson('/api/v1/funding/payments', $extra + [
            'client_workspace_id' => $p->client_workspace_id, 'project_id' => $p->id, 'paid_on' => $this->day(0), 'idempotency_key' => (string) Str::uuid(),
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

    private function sweep(): void
    {
        $this->artisan('funding:refresh')->assertSuccessful();
        $this->holdingTenant((string) $this->tenant->id);
    }

    /** @return list<string> */
    private function notices(User $u): array
    {
        return AppNotification::query()->where('user_id', $u->id)->where('type', 'project_budget')->orderBy('created_at')->pluck('entity_type')->all();
    }

    // ---- migration defaults ------------------------------------------------------------------------------------------------

    public function test_an_existing_budget_row_becomes_direct_with_no_plan_and_nothing_else_changes(): void
    {
        $p = $this->project();
        DB::table('project_funding')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'client_workspace_id' => $p->client_workspace_id,
            'project_id' => $p->id, 'payment_method' => 'prepaid', 'alert_floor' => 2000, 'created_at' => now(), 'updated_at' => now()]);
        $row = ProjectFunding::query()->where('project_id', $p->id)->firstOrFail();
        $this->assertSame('direct', $row->payment_mode);
        $this->assertNull($row->platform_plan);
    }

    public function test_a_budget_added_without_a_mode_is_direct_and_behaves_as_before(): void
    {
        $p = $this->project();
        $this->spend($p, 3, 1_000);
        $r = $this->add($p, ['amount' => 10_000])->assertCreated()->json('data');
        $this->assertSame('direct', $r['payment_mode']);
        $this->assertEqualsWithDelta(9_000.0, (float) $r['remaining'], 0.01);
        $this->assertNull($r['ceiling']);
        $this->assertNull($r['to_invoice']);
        $this->assertFalse($r['planned']);
        $this->assertNull($r['period_end']);
        $this->add($p, ['amount' => 0])->assertUnprocessable();
    }

    // ---- credit ------------------------------------------------------------------------------------------------------------

    public function test_a_credit_budget_counts_spend_only_inside_its_period_and_has_no_remaining(): void
    {
        $p = $this->project();
        $this->spend($p, 30, 100);            // 2026-10-14 — before the period
        $this->spend($p, 20, 200);            // 2026-10-24
        $this->spend($p, 15, 100, 'USD');     // 2026-10-29 — 100 USD = 375 SAR
        $this->spend($p, 8, 300);             // 2026-11-05
        $this->spend($p, 1, 400);             // 2026-11-12 — after the period

        $r = $this->add($p, ['payment_mode' => 'credit', 'period_start' => $this->day(25), 'period_end' => $this->day(2)])->assertCreated()->json('data');
        $this->assertSame('credit', $r['payment_mode']);
        $this->assertNull($r['remaining'], 'nothing «remains» of a credit budget');
        $this->assertNull($r['ceiling']);
        $this->assertSame('credit', $r['status']);

        $d = $this->figures($p);
        $this->assertEqualsWithDelta(875.0, (float) $d['to_date'], 0.01);
        $this->assertEqualsWithDelta(875.0, (float) $d['spent'], 0.01);
        $months = collect($d['invoice_months'])->keyBy('month');
        $this->assertEqualsWithDelta(575.0, (float) $months['2026-10']['spent'], 0.01, 'USD converted with the existing FX');
        $this->assertTrue($months['2026-10']['closed']);
        $this->assertEqualsWithDelta(300.0, (float) $months['2026-11']['spent'], 0.01);
        $this->assertFalse($months['2026-11']['closed'], 'the running month is not ready to invoice');
        $this->assertEqualsWithDelta(575.0, (float) $d['to_invoice'], 0.01, 'to invoice = the closed months');
        $this->assertEqualsWithDelta(300.0, (float) $d['this_month'], 0.01, '«هذا الشهر» = the running month');
        $this->assertSame([], array_values(array_diff(array_column($d['by_platform'], 'provider'), ['snapchat'])));
        $this->assertSame(1, FundingPayment::query()->where('project_id', $p->id)->count(), 'recording a credit budget moves and invoices nothing');
    }

    public function test_a_credit_ceiling_has_its_own_bar_status_and_one_notice_per_state(): void
    {
        $p = $this->project();
        $this->add($p, ['payment_mode' => 'credit', 'amount' => 1_000])->assertCreated();
        $this->assertSame(['funding:added'], $this->notices($this->manager));
        $this->assertStringContainsString('تُفوتر لاحقًا', (string) AppNotification::query()->where('user_id', $this->manager->id)->firstOrFail()->message_ar);

        $this->spend($p, 2, 900);
        $this->sweep();
        $f = $this->figures($p);
        $this->assertSame('credit_near', $f['status']);
        $this->assertEqualsWithDelta(0.9, (float) $f['ceiling_ratio'], 0.001);
        $this->assertContains('funding:ceiling_near', $this->notices($this->manager));
        $this->assertContains('funding:ceiling_near', $this->notices($this->finance));
        $this->sweep();
        $this->assertCount(1, array_keys($this->notices($this->manager), 'funding:ceiling_near'), 'said once per cycle');

        $this->spend($p, 1, 300);
        $this->sweep();
        $this->assertSame('credit_over', $this->figures($p)['status']);
        $this->assertContains('funding:ceiling_over', $this->notices($this->manager));
        $this->assertNull($this->figures($p)['remaining']);
    }

    public function test_a_closed_month_is_announced_to_finance_once_with_nothing_generated(): void
    {
        $p = $this->project();
        $this->spend($p, 20, 500);   // 2026-10-24
        $this->spend($p, 5, 100);    // 2026-11-08
        $this->add($p, ['payment_mode' => 'credit'])->assertCreated();
        $before = FundingPayment::query()->count();

        $this->sweep();
        $this->assertSame(['funding:invoice'], array_values(array_filter($this->notices($this->finance), static fn ($t) => $t === 'funding:invoice')));
        $this->assertNotContains('funding:invoice', $this->notices($this->manager), 'finance only');
        $n = AppNotification::query()->where('user_id', $this->finance->id)->where('entity_type', 'funding:invoice')->firstOrFail();
        $this->assertStringContainsString('2026-10-01', (string) $n->message_ar);
        $this->assertStringContainsString('500', (string) $n->message_ar);
        $this->assertStringContainsString('الأرقام هنا مأخوذة من المنصات', (string) $n->message_ar, 'the financial disclaimer');

        $this->sweep();
        $this->assertCount(1, AppNotification::query()->where('entity_type', 'funding:invoice')->where('user_id', $this->finance->id)->get(), 'once per month');
        $this->assertSame($before, FundingPayment::query()->count(), 'no ledger entry, no invoice, no money action');
    }

    public function test_credit_to_direct_needs_an_amount_and_an_edit_of_mode_is_undoable(): void
    {
        $p = $this->project();
        $this->add($p, ['payment_mode' => 'credit'])->assertCreated();
        $this->act($p, 'adjust', ['payment_mode' => 'direct'])->assertUnprocessable();

        $r = $this->act($p, 'adjust', ['payment_mode' => 'direct', 'amount' => 5_000])->assertOk()->json('data');
        $this->assertSame('direct', $r['payment_mode']);
        $this->assertEqualsWithDelta(5_000.0, (float) $r['remaining'], 0.01);

        $back = $this->act($p, 'undo')->assertOk()->json('data');
        $this->assertSame('credit', $back['payment_mode']);
        $this->assertNull($back['remaining']);
        $this->assertSame(1, AuditLog::query()->where('action', 'funding.adjusted')->count());
    }

    // ---- planned -----------------------------------------------------------------------------------------------------------

    public function test_a_plan_by_percent_or_amount_must_add_up_and_is_stored_as_shares(): void
    {
        $p = $this->project();
        $this->add($p, ['amount' => 10_000, 'platform_plan' => [['provider' => 'snapchat', 'percent' => 60], ['provider' => 'meta', 'percent' => 30]]])
            ->assertUnprocessable()->assertJsonValidationErrors('platform_plan');
        $this->add($p, ['amount' => 10_000, 'platform_plan' => [['provider' => 'snapchat', 'amount' => 6_000], ['provider' => 'meta', 'amount' => 3_000]]])->assertUnprocessable();
        $this->add($p, ['amount' => 10_000, 'platform_plan' => [['provider' => 'snapchat', 'amount' => 6_000], ['provider' => 'meta', 'percent' => 40]]])->assertUnprocessable();
        $this->assertSame(0, ProjectFunding::query()->where('project_id', $p->id)->count(), 'a refused plan leaves no budget behind');

        $r = $this->add($p, ['amount' => 10_000, 'platform_plan' => [['provider' => 'snapchat', 'amount' => 6_000], ['provider' => 'meta', 'amount' => 4_000]]])->assertCreated()->json('data');
        $this->assertTrue($r['planned']);
        $this->assertEqualsWithDelta(60.0, (float) $r['platform_plan'][0]['percent'], 0.001);
        $this->assertEqualsWithDelta(4_000.0, (float) $r['platform_plan'][1]['amount'], 0.01);

        // The mode and the plan are set when a budget starts; a renewal cannot change them silently.
        $this->add($p, ['amount' => 1_000, 'platform_plan' => [['provider' => 'meta', 'percent' => 100]]])->assertUnprocessable();
        $renewed = $this->add($p, ['amount' => 10_000])->assertCreated()->json('data');
        $this->assertEqualsWithDelta(12_000.0, (float) $renewed['platform_plan'][0]['amount'], 0.01, 'the shares follow the budget, so they always add up');
    }

    public function test_plan_against_actual_states_share_pace_projection_and_chips(): void
    {
        $p = $this->project();
        $this->spend($p, 6, 2_500);
        $this->spend($p, 3, 2_500);
        $this->spend($p, 2, 200, 'SAR', 'meta');
        $this->add($p, ['amount' => 10_000, 'period_start' => $this->day(9), 'period_end' => Carbon::today('Asia/Riyadh')->addDays(17)->toDateString(),
            'platform_plan' => [['provider' => 'snapchat', 'percent' => 60], ['provider' => 'meta', 'percent' => 40]]])->assertCreated();

        $rows = collect($this->figures($p)['plan'])->keyBy('provider');
        $snap = $rows['snapchat'];
        $this->assertEqualsWithDelta(6_000.0, (float) $snap['planned'], 0.01);
        $this->assertEqualsWithDelta(5_000.0, (float) $snap['spent'], 0.01);
        $this->assertEqualsWithDelta(0.8333, (float) $snap['share_used'], 0.001);
        $this->assertEqualsWithDelta(714.29, (float) $snap['pace_per_day'], 0.01);
        $this->assertEqualsWithDelta(5_000 + 714.29 * 17, (float) $snap['projected'], 1.0);
        $this->assertSame('ahead', $snap['flag']);
        $this->assertSame('behind', $rows['meta']['flag']);

        // 100% of its share: a notice, once, naming the figure and no cause.
        $this->spend($p, 1, 1_000);
        $this->sweep();
        $this->assertSame('over', collect($this->figures($p)['plan'])->firstWhere('provider', 'snapchat')['flag']);
        $this->assertContains('funding:plan', $this->notices($this->manager));
        $this->sweep();
        $this->assertCount(1, array_keys($this->notices($this->manager), 'funding:plan'));
        $msg = (string) AppNotification::query()->where('entity_type', 'funding:plan')->firstOrFail()->message_ar;
        $this->assertStringContainsString('100%', $msg);
        $this->assertStringNotContainsString('بسبب', $msg);
    }

    public function test_reallocation_is_an_undoable_entry_with_who_when_why_and_keeps_what_was_spent(): void
    {
        $p = $this->project();
        $this->spend($p, 2, 6_500);
        $this->spend($p, 2, 500, 'SAR', 'meta');
        $this->add($p, ['amount' => 10_000, 'platform_plan' => [['provider' => 'snapchat', 'percent' => 60], ['provider' => 'meta', 'percent' => 40]]])->assertCreated();

        $r = $this->act($p, 'reallocate', ['reason' => 'سناب أسرع من الخطة'])->assertOk()->json('data');
        $plan = collect($r['platform_plan'])->keyBy('provider');
        $this->assertEqualsWithDelta(6_500.0, (float) $plan['snapchat']['amount'], 0.01, 'what was spent stays where it was spent');
        $this->assertEqualsWithDelta(3_500.0, (float) $plan['meta']['amount'], 0.01, 'the rest goes where room was left');
        $this->assertEqualsWithDelta(10_000.0, (float) $r['approved'], 0.01, 'the budget is unchanged');

        $entry = FundingPayment::query()->findOrFail($r['entry_id']);
        $this->assertSame('reallocation', $entry->kind);
        $this->assertSame('0.00', $entry->amount_sar);
        $this->assertSame('سناب أسرع من الخطة', $entry->reason);
        $audit = AuditLog::query()->where('action', 'funding.reallocated')->firstOrFail();
        $this->assertSame($this->finance->id, $audit->user_id);
        $this->assertSame('سناب أسرع من الخطة', $audit->reason);

        $this->act($p, 'reallocate')->assertUnprocessable(); // already balanced: nothing to change

        $undo = $this->act($p, 'undo')->assertOk()->json('data');
        $this->assertEqualsWithDelta(6_000.0, (float) collect($undo['platform_plan'])->firstWhere('provider', 'snapchat')['amount'], 0.01);
        $this->assertSame(3, FundingPayment::query()->where('project_id', $p->id)->count(), 'initial + reallocation + its reversal');

        $manual = $this->act($p, 'reallocate', ['platform_plan' => [['provider' => 'snapchat', 'percent' => 50], ['provider' => 'meta', 'percent' => 50]]])->assertOk()->json('data');
        $this->assertEqualsWithDelta(5_000.0, (float) collect($manual['platform_plan'])->firstWhere('provider', 'meta')['amount'], 0.01);
    }

    public function test_a_budget_without_a_plan_cannot_be_reallocated_and_a_plan_can_be_added_by_edit(): void
    {
        $p = $this->project();
        $this->add($p, ['amount' => 5_000])->assertCreated();
        $this->act($p, 'reallocate')->assertUnprocessable()->assertJsonValidationErrors('platform_plan');

        $r = $this->act($p, 'adjust', ['platform_plan' => [['provider' => 'meta', 'percent' => 70], ['provider' => 'tiktok', 'percent' => 30]]])->assertOk()->json('data');
        $this->assertTrue($r['planned']);
        $cleared = $this->act($p, 'adjust', ['platform_plan' => null])->assertOk()->json('data');
        $this->assertFalse($cleared['planned']);
        $this->assertTrue($this->act($p, 'undo')->assertOk()->json('data.planned'));
    }

    // ---- isolation ---------------------------------------------------------------------------------------------------------

    public function test_other_tenants_confined_members_and_readers_cannot_touch_modes_or_plans(): void
    {
        $p = $this->project();
        $this->add($p, ['amount' => 5_000, 'platform_plan' => [['provider' => 'meta', 'percent' => 100]]])->assertCreated();

        $reader = $this->user(['funding.view', 'clients.view', 'clients.view_all', 'projects.view.all']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->act($p, 'reallocate', [], $reader)->assertForbidden();
        $this->act($p, 'adjust', ['payment_mode' => 'credit'], $reader)->assertForbidden();

        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active', 'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        $stranger = $this->user(['funding.manage', 'funding.view', 'clients.view', 'clients.view_all', 'projects.view.all'], $other);
        $this->holdingTenant((string) $other->id);
        $this->act($p, 'reallocate', [], $stranger)->assertNotFound();
        $this->act($p, 'adjust', ['payment_mode' => 'credit'], $stranger)->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/funding')->assertOk()->assertJsonCount(0, 'data');

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $confined = $this->user(['funding.manage', 'funding.view', 'clients.view']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->act($p, 'reallocate', [], $confined)->assertNotFound();
        $this->assertSame(1, FundingPayment::query()->where('project_id', $p->id)->count());
    }

    // ---- exports -----------------------------------------------------------------------------------------------------------

    public function test_the_exports_carry_mode_period_plan_vs_actual_to_invoice_and_the_disclaimer(): void
    {
        $credit = $this->project();
        $this->spend($credit, 20, 500);
        $this->add($credit, ['payment_mode' => 'credit', 'amount' => 2_000, 'period_start' => $this->day(25)])->assertCreated();
        $planned = $this->project();
        $this->spend($planned, 3, 100);
        $this->add($planned, ['amount' => 1_000, 'platform_plan' => [['provider' => 'snapchat', 'percent' => 100]]])->assertCreated();

        $csv = $this->actingAs($this->finance, 'sanctum')->get('/api/v1/funding/export?format=csv&sheet=summary')->assertOk();
        $body = $csv->getContent();
        $head = str_getcsv(explode("\n", ltrim($body, "\xEF\xBB\xBF"))[0]);
        foreach (['payment_mode', 'ceiling_sar', 'to_invoice_sar', 'this_month_sar', 'plan_vs_actual_usd', 'platforms_usd', 'period_start', 'period_end'] as $col) {
            $this->assertContains($col, $head);
        }
        $this->assertStringContainsString('credit', $body);
        $this->assertStringContainsString('snapchat: 266.67 / 26.67', $body, 'a platform is in USD: 1,000 SAR at the 3.75 peg');
        $this->assertStringContainsString('operational follow-up', (string) $csv->headers->get('X-Financial-Disclaimer'));

        $inv = $this->actingAs($this->finance, 'sanctum')->get('/api/v1/funding/export?format=csv&sheet=invoice')->assertOk()->getContent();
        $this->assertStringContainsString('2026-10', $inv);
        $plan = $this->actingAs($this->finance, 'sanctum')->get('/api/v1/funding/export?format=csv&sheet=plan')->assertOk()->getContent();
        $this->assertStringContainsString('snapchat', $plan);

        $x = $this->actingAs($this->finance, 'sanctum')->get('/api/v1/funding/export?format=xlsx')->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($tmp, $x->getContent());
        $book = IOFactory::load($tmp);
        @unlink($tmp);
        $this->assertSame(['summary', 'movements', 'invoice_months', 'plan_vs_actual'], $book->getSheetNames());
        $first = collect($book->getSheet(0)->toArray())->pluck(0)->all();
        foreach (['statement', 'organisation', 'statement_period', 'currency', 'generated_at', 'generated_by', 'note'] as $k) {
            $this->assertContains($k, $first, "identity row {$k}");
        }
        $this->assertNotContains('vat_number', $first, 'a field the workspace does not hold is not invented');
        foreach ([1, 2, 3] as $i) {
            $this->assertStringContainsString('الأرقام هنا مأخوذة من المنصات', json_encode($book->getSheet($i)->toArray(), JSON_UNESCAPED_UNICODE), "sheet {$i} carries the disclaimer");
        }
    }

    public function test_index_totals_keep_credit_out_of_the_remaining(): void
    {
        $direct = $this->project();
        $this->add($direct, ['amount' => 1_000])->assertCreated();
        $credit = $this->project();
        $this->spend($credit, 2, 400);
        $this->add($credit, ['payment_mode' => 'credit'])->assertCreated();

        $t = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding')->assertOk()->json('meta.totals');
        $this->assertEqualsWithDelta(1_000.0, (float) $t['remaining'], 0.01);
        $this->assertSame(1, $t['credit_count']);
        $this->assertEqualsWithDelta(400.0, (float) $t['credit_spent'], 0.01);
    }

    public function test_a_period_may_start_next_month_and_counts_nothing_before_it(): void
    {
        $p = $this->project();
        $this->spend($p, 2, 700);
        $nextMonth = Carbon::today('Asia/Riyadh')->addMonthNoOverflow()->startOfMonth();
        $r = $this->add($p, ['payment_mode' => 'credit', 'period_start' => $nextMonth->toDateString(),
            'period_end' => $nextMonth->copy()->endOfMonth()->toDateString()])->assertCreated()->json('data');
        $this->assertSame($nextMonth->toDateString(), $r['period_start']);
        $this->assertEqualsWithDelta(0.0, (float) $r['spent'], 0.01, 'spend before the period is not this budget\'s');
    }

    public function test_a_platform_is_in_usd_and_a_day_with_no_rate_keeps_its_own_currency(): void
    {
        $p = $this->project();
        $this->spend($p, 3, 375.0, 'SAR', 'snapchat');
        $this->spend($p, 2, 100.0, 'EUR', 'meta');   // no EUR rate exists: never guessed
        $r = $this->add($p, ['amount' => 5_000])->assertCreated()->json('data');
        $by = collect($r['by_platform'])->keyBy('provider');
        $this->assertEqualsWithDelta(100.0, (float) $by['snapchat']['spent_usd'], 0.01, '375 SAR is 100 USD at the 3.75 peg');
        $this->assertEquals([['currency' => 'EUR', 'amount' => 100.0]], $by['meta']['unconverted'], 'the amount stays in its own currency');
        $this->assertSame('incomplete', $r['status']);
    }

    public function test_the_statement_html_renders_from_real_credit_and_planned_figures_in_both_languages(): void
    {
        $credit = $this->project();
        $this->spend($credit, 20, 500);
        $this->spend($credit, 3, 250, 'USD');
        $this->add($credit, ['payment_mode' => 'credit', 'amount' => 3_000, 'period_start' => $this->day(25)])->assertCreated();
        $planned = $this->project();
        $this->spend($planned, 2, 100, 'SAR', 'snapchat');
        $this->spend($planned, 2, 50, 'EUR', 'meta');
        $this->add($planned, ['amount' => 1_000, 'platform_plan' => [['provider' => 'snapchat', 'percent' => 60], ['provider' => 'meta', 'percent' => 40]]])->assertCreated();

        $rows = [];
        foreach ([$credit, $planned] as $p) {
            $rows[] = ['client' => 'عميل', 'project' => $p->name] + $this->figures($p) + ['invoice_months' => $this->figures($p)['invoice_months']];
        }
        foreach (['ar', 'en'] as $lang) {
            $html = app(FundingPdf::class)->html($rows, [], '2026-11-13', $lang);
            $this->assertStringContainsString('data-grid="platforms"', $html);
            $this->assertStringContainsString('data-grid="invoice"', $html);
            $this->assertStringContainsString($lang === 'ar' ? 'بدون سعر صرف' : 'No FX rate', $html, 'the EUR day keeps its own flag');
        }
    }
}
