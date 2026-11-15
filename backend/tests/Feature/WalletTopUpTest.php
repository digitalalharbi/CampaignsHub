<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Billing\Models\ClientBillingProfile;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Funding\Services\WalletTopUp;
use App\Domains\Tenancy\Enums\Portal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/**
 * FIN-WALLET-001 — finance and the budgets are one loop: a confirmed payment of a «شحن رصيد إعلاني» invoice is appended to
 * the project's budget ledger and raises its remaining; every deposit says where it came from. Fixture names only.
 */
final class WalletTopUpTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    private User $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFunding();
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->agency = User::create(['name' => 'AO', 'email' => 'ao-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->agency, $this->tenant, Portal::Agency);
        $this->agency->assignRole($role);
    }

    public function test_a_recorded_payment_of_a_top_up_invoice_raises_the_budget_remaining_and_names_its_source(): void
    {
        $project = $this->newProject('University', 'Campus');
        $this->spendRow($project, (string) Str::uuid(), 2, 4_404);
        $this->addBudget($project, 5_069, 6);
        ClientBillingProfile::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id,
            'fee_model' => 'manual', 'cadence' => 'monthly', 'currency' => 'SAR', 'tax_treatment' => 'basic_15', 'due_days' => 7]);

        $this->actingAs($this->agency, 'sanctum');
        $before = $this->getJson('/api/v1/funding/projects/'.$project->id)->assertOk()->json('data.remaining');
        $this->assertEqualsWithDelta(665, $before, 0.001);

        $invoice = $this->postJson('/api/v1/billing/clients/'.$project->client_workspace_id.'/invoices/wallet-top-up', [
            'project_id' => $project->id, 'amount' => 1_000,
        ])->assertCreated()->json('data');
        $this->assertSame('wallet_top_up', $invoice['purpose']);
        $this->assertEqualsWithDelta(1_150, (float) $invoice['total'], 0.001); // 15% VAT on top

        // Not paid yet: nothing moves.
        $this->assertEqualsWithDelta(665, $this->getJson('/api/v1/funding/projects/'.$project->id)->json('data.remaining'), 0.001);

        $this->postJson('/api/v1/billing/invoices/'.$invoice['id'].'/review')->assertOk();
        $this->postJson('/api/v1/billing/invoices/'.$invoice['id'].'/issue')->assertOk();
        $this->postJson('/api/v1/billing/invoices/'.$invoice['id'].'/payment', ['amount' => 1_150])->assertOk();

        $after = $this->getJson('/api/v1/funding/projects/'.$project->id)->assertOk()->json('data');
        // The VAT is not ad money: the deposit is the subtotal share of what was paid.
        $this->assertEqualsWithDelta(1_665, $after['remaining'], 0.001);
        $deposit = collect($after['history'])->firstWhere('kind', 'top_up');
        $this->assertSame('invoice_payment', $deposit['source']['type']);
        $this->assertSame($invoice['number'], $deposit['source']['invoice_number']);
        $this->assertSame('manual', $deposit['source']['via']);
        // The first amount, typed by finance, is a manual entry with its reference.
        $first = collect($after['history'])->firstWhere('kind', 'initial');
        $this->assertSame(['type' => 'manual', 'reference' => 'TRX-1'], $first['source']);

        // The same remaining on the client page.
        $this->assertEqualsWithDelta(1_665, $this->getJson('/api/v1/app/clients/'.$project->client_workspace_id)->json('data.budget.total.remaining'), 0.001);
    }

    public function test_a_gateway_confirmation_adds_once_and_a_fee_invoice_adds_nothing(): void
    {
        $project = $this->newProject('Store', 'Shop');
        $this->addBudget($project, 2_000, 3);
        $invoice = Invoice::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'number' => 'INV-T-1',
            'currency' => 'SAR', 'subtotal' => 500, 'tax' => 0, 'tax_treatment' => 'zero_rated', 'discount' => 0, 'total' => 500, 'line_items' => [],
            'amount_paid' => 0, 'status' => 'issued', 'source' => 'wallet_top_up', 'purpose' => 'wallet_top_up', 'project_id' => $project->id]);
        $fee = Invoice::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'number' => 'INV-T-2',
            'currency' => 'SAR', 'subtotal' => 300, 'tax' => 0, 'tax_treatment' => 'zero_rated', 'discount' => 0, 'total' => 300, 'line_items' => [],
            'amount_paid' => 0, 'status' => 'issued', 'source' => 'billing_profile']);

        $topUp = app(WalletTopUp::class);
        $topUp->fromInvoicePayment($invoice, 500, 'gateway:pay-1', null, 'pay-1');
        $topUp->fromInvoicePayment($invoice, 500, 'gateway:pay-1', null, 'pay-1'); // a retried webhook
        $this->assertNull($topUp->fromInvoicePayment($fee, 300, 'gateway:pay-2', null, 'pay-2'));

        $this->assertEqualsWithDelta(2_500, (float) DB::table('funding_payments')->where('project_id', $project->id)->sum('amount_sar'), 0.001);
        $this->assertSame(1, DB::table('funding_payments')->where('project_id', $project->id)->where('kind', 'top_up')->count());
    }
}
