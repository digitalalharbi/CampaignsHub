<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\ClientBillingProfile;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Services\ClientInvoiceService;
use App\Domains\Billing\Support\ImmutableInvoice;
use App\Domains\Billing\Support\IncompleteFeeBasis;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INV-PROFILE-001, INV-LIFECYCLE-001, INV-IMMUTABLE-001 — the agency-to-client fee invoice: drafted from a
 * billing profile on the canonical money, reviewed before it is issued, frozen once issued, corrected by a
 * new version, and due or overdue by the calendar alone.
 */
final class ClientInvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Store', 'status' => 'active']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
    }

    private function profile(array $over = []): ClientBillingProfile
    {
        return ClientBillingProfile::create($over + [
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id,
            'fee_model' => 'fixed', 'fixed_amount' => 1000, 'cadence' => 'weekly', 'currency' => 'SAR',
            'tax_treatment' => 'basic_15', 'due_days' => 7,
        ]);
    }

    private function spend(?float $value, ?float $original = null, string $date = '2026-10-12'): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => null, 'provider' => 'sandbox', 'metric_key' => 'spend', 'metric_date' => $date,
            'value' => $value, 'original_amount' => $original, 'original_currency' => $original === null ? null : 'USD',
            'project_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): ClientInvoiceService
    {
        return app(ClientInvoiceService::class);
    }

    private function periodStart(): Carbon
    {
        return Carbon::parse('2026-10-11');
    }

    private function periodEnd(): Carbon
    {
        return Carbon::parse('2026-10-17');
    }

    public function test_a_fixed_fee_draft_carries_vat_and_asking_again_does_not_bill_twice(): void
    {
        $profile = $this->profile();

        $invoice = $this->service()->draftFor($profile, $this->periodStart(), $this->periodEnd());

        $this->assertSame('draft', $invoice->status);
        $this->assertEquals(1000.00, (float) $invoice->subtotal);
        $this->assertEquals(150.00, (float) $invoice->tax);
        $this->assertEquals(1150.00, (float) $invoice->total);
        $this->assertSame('billing_profile', $invoice->source);
        $this->assertSame('2026-10-11', $invoice->period_start->toDateString());

        $this->assertSame($invoice->getKey(), $this->service()->draftFor($profile, $this->periodStart(), $this->periodEnd())->getKey(), 'one live invoice per client per period');
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_a_percentage_fee_is_taken_of_the_canonical_spend_converted_at_the_legal_peg_with_a_minimum(): void
    {
        $profile = $this->profile(['fee_model' => 'percentage', 'fixed_amount' => null, 'percentage' => 10, 'minimum_fee' => 100, 'tax_treatment' => 'zero_rated']);
        $this->spend(2000);   // USD 2,000 of managed spend in the period

        $invoice = $this->service()->draftFor($profile, $this->periodStart(), $this->periodEnd());

        // 2,000 USD × 3.75 (the SAR peg) = 7,500 SAR; 10% = 750 SAR.
        $this->assertEquals(750.00, (float) $invoice->subtotal);
        $this->assertEquals(0.00, (float) $invoice->tax);
        $this->assertSame(2000.0, (float) $invoice->profile_snapshot['basis']['spend']);
        $this->assertSame('SAR', $invoice->currency);
    }

    public function test_a_percentage_fee_never_falls_below_the_minimum(): void
    {
        $profile = $this->profile(['fee_model' => 'percentage', 'fixed_amount' => null, 'percentage' => 1, 'minimum_fee' => 500]);
        $this->spend(100);

        $this->assertEquals(500.00, (float) $this->service()->draftFor($profile, $this->periodStart(), $this->periodEnd())->subtotal);
    }

    public function test_a_percentage_fee_is_refused_when_any_of_the_spend_is_withheld(): void
    {
        $profile = $this->profile(['fee_model' => 'percentage', 'fixed_amount' => null, 'percentage' => 10]);
        $this->spend(2000);
        $this->spend(null, 500);   // no rate: value withheld, original kept

        $this->expectException(IncompleteFeeBasis::class);
        $this->service()->draftFor($profile, $this->periodStart(), $this->periodEnd());
    }

    public function test_a_manual_fee_needs_its_amount(): void
    {
        $profile = $this->profile(['fee_model' => 'manual', 'fixed_amount' => null]);

        $this->assertEquals(321.5, (float) $this->service()->draftFor($profile, $this->periodStart(), $this->periodEnd(), 321.5)->subtotal);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->draftFor($profile, Carbon::parse('2026-10-04'), Carbon::parse('2026-10-10'));
    }

    public function test_an_invoice_must_be_reviewed_before_it_can_be_issued_and_issuing_sets_the_due_date(): void
    {
        $invoice = $this->service()->draftFor($this->profile(), $this->periodStart(), $this->periodEnd());

        try {
            $this->service()->issue($invoice);
            $this->fail('a draft must not be issued unreviewed');
        } catch (\InvalidArgumentException) {
            $this->assertSame('draft', $invoice->fresh()->status);
        }

        $this->service()->review($invoice, $this->owner);
        $this->assertSame('reviewed', $invoice->fresh()->status);
        $this->assertSame($this->owner->id, (int) $invoice->fresh()->reviewed_by);

        $issued = $this->service()->issue($invoice->fresh());
        $this->assertSame('issued', $issued->status);
        $this->assertNotNull($issued->issued_at);
        $this->assertSame(Carbon::today()->addDays(7)->toDateString(), $issued->due_date->toDateString());

        $this->assertSame('sent', $this->service()->markSent($issued->fresh())->status);
    }

    public function test_an_issued_invoices_figures_cannot_be_edited_but_its_settlement_can_move(): void
    {
        $invoice = $this->issued();

        foreach ([['total' => 1], ['line_items' => []], ['due_date' => '2030-01-01'], ['number' => 'X-1'], ['currency' => 'USD']] as $change) {
            try {
                $invoice->fresh()->update($change);
                $this->fail('an issued invoice accepted '.key($change));
            } catch (ImmutableInvoice) {
                $this->assertEquals(1150.00, (float) $invoice->fresh()->total);
            }
        }

        $this->service()->recordPayment($invoice->fresh(), 150, $this->owner);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->service()->recordPayment($invoice->fresh(), 1000, $this->owner);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEquals(1150.00, (float) $invoice->fresh()->amount_paid);
    }

    public function test_a_mistake_is_corrected_by_a_new_version_and_the_original_is_voided_with_its_reason(): void
    {
        $invoice = $this->issued();

        $copy = $this->service()->correct($invoice->fresh(), 'Wrong period', $this->owner);

        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame('Wrong period', $invoice->fresh()->correction_reason);
        $this->assertSame('draft', $copy->status);
        $this->assertSame($invoice->getKey(), (int) $copy->supersedes_id);
        $this->assertNotSame($invoice->number, $copy->number);
        $this->assertEquals(1150.00, (float) $invoice->fresh()->total, 'the voided record keeps its figures');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->correct($copy, 'x', $this->owner);   // a draft is edited, not corrected
    }

    public function test_due_and_overdue_are_read_from_the_calendar_not_stored(): void
    {
        $invoice = $this->issued();   // due in 7 days
        $this->assertSame('due', $invoice->fresh()->displayStatus());
        $this->assertSame('overdue', $invoice->fresh()->displayStatus(Carbon::today()->addDays(8)));

        $this->service()->recordPayment($invoice->fresh(), 1150, $this->owner);
        $this->assertSame('paid', $invoice->fresh()->displayStatus(Carbon::today()->addDays(30)), 'a paid invoice is never overdue');
    }

    private function issued(): Invoice
    {
        $invoice = $this->service()->draftFor($this->profile(), $this->periodStart(), $this->periodEnd());
        $this->service()->review($invoice, $this->owner);

        return $this->service()->issue($invoice->fresh());
    }
}
