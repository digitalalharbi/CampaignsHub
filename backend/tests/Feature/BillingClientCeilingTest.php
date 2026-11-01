<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * SEC-CLUSTER-4 — the tenant billing lists, the finance aggregates and the quote/payment actions honour the
 * membership's client ceiling. An operator named to client B never reads, approves or pays client A's money.
 */
final class BillingClientCeilingTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    private Quote $quoteA;

    private Quote $quoteB;

    private Invoice $invoiceA;

    private Invoice $invoiceB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
        $this->quoteA = $this->quote('QUO-A', $this->clientA->id);
        $this->quoteB = $this->quote('QUO-B', $this->clientB->id);
        $this->invoiceA = $this->invoice('INV-A', $this->clientA->id, 100);
        $this->invoiceB = $this->invoice('INV-B', $this->clientB->id, 200);
        Payment::create(['tenant_id' => $this->agency->id, 'invoice_id' => $this->invoiceA->id, 'provider' => 'fake', 'amount' => 100, 'currency' => 'SAR', 'status' => 'pending', 'idempotency_key' => 'k-a']);
        Payment::create(['tenant_id' => $this->agency->id, 'invoice_id' => $this->invoiceB->id, 'provider' => 'fake', 'amount' => 200, 'currency' => 'SAR', 'status' => 'pending', 'idempotency_key' => 'k-b']);
    }

    private function quote(string $number, string $clientId): Quote
    {
        return Quote::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $clientId, 'number' => $number,
            'currency' => 'SAR', 'subtotal' => 10, 'tax' => 0, 'discount' => 0, 'total' => 10, 'status' => 'draft']);
    }

    private function invoice(string $number, string $clientId, float $total): Invoice
    {
        return Invoice::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $clientId, 'number' => $number,
            'currency' => 'SAR', 'subtotal' => $total, 'tax' => 0, 'discount' => 0, 'total' => $total, 'amount_paid' => 0,
            'status' => 'issued', 'due_date' => now()->subDays(3)]);
    }

    public function test_the_lists_show_a_scoped_operator_only_their_clients_money(): void
    {
        $op = $this->scopedTo($this->clientB, ['billing.view']);

        $quotes = $this->actingAs($op, 'sanctum')->getJson('/api/v1/billing/quotes')->assertOk();
        $this->assertSame(['QUO-B'], array_column($quotes->json('data'), 'number'));
        $this->assertSame(1, $quotes->json('meta.total'));

        $invoices = $this->actingAs($op, 'sanctum')->getJson('/api/v1/billing/invoices')->assertOk();
        $this->assertSame(['INV-B'], array_column($invoices->json('data'), 'number'));

        $payments = $this->actingAs($op, 'sanctum')->getJson('/api/v1/billing/payments')->assertOk();
        $this->assertSame(['INV-B'], array_column(array_column($payments->json('data'), 'invoice'), 'number'));

        $recv = $this->actingAs($op, 'sanctum')->getJson('/api/v1/billing/receivables')->assertOk();
        $this->assertSame(['INV-B'], array_column($recv->json('data'), 'number'));

        $overview = $this->actingAs($op, 'sanctum')->getJson('/api/v1/billing/overview')->assertOk();
        $this->assertSame(1, $overview->json('data.invoices.count'));
        $this->assertEquals(200, $overview->json('data.invoices.total'));
        $this->assertEquals(1, $overview->json('data.quotes.count'));
    }

    public function test_an_unrestricted_operator_still_reads_everything(): void
    {
        $owner = $this->owner();

        $this->assertCount(2, $this->actingAs($owner, 'sanctum')->getJson('/api/v1/billing/quotes')->json('data'));
        $this->assertCount(2, $this->actingAs($owner, 'sanctum')->getJson('/api/v1/billing/invoices')->json('data'));
        $this->assertCount(2, $this->actingAs($owner, 'sanctum')->getJson('/api/v1/billing/payments')->json('data'));
        $this->assertEquals(300, $this->actingAs($owner, 'sanctum')->getJson('/api/v1/billing/overview')->json('data.invoices.total'));
    }

    public function test_a_scoped_operator_cannot_approve_or_pay_another_clients_documents(): void
    {
        $op = $this->scopedTo($this->clientB, ['billing.view', 'billing.manage']);

        $this->actingAs($op, 'sanctum')->postJson("/api/v1/billing/quotes/{$this->quoteA->id}/approve")->assertNotFound();
        $this->actingAs($op, 'sanctum')->postJson("/api/v1/billing/invoices/{$this->invoiceA->id}/pay")->assertNotFound();
        $this->assertSame('draft', $this->quoteA->fresh()->status);

        $this->actingAs($op, 'sanctum')->postJson("/api/v1/billing/quotes/{$this->quoteB->id}/approve")->assertCreated();
    }

    public function test_a_scoped_operator_cannot_file_a_quote_into_another_clients_portal(): void
    {
        $op = $this->scopedTo($this->clientB, ['billing.view', 'billing.manage']);

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/billing/quotes', ['client_workspace_id' => (string) $this->clientA->id, 'subtotal' => 5])->assertForbidden();
        $this->actingAs($op, 'sanctum')->postJson('/api/v1/billing/quotes', ['project_id' => (string) $this->projectA->id, 'subtotal' => 5])->assertForbidden();
        // No client at all would create a row the operator could not see again.
        $this->actingAs($op, 'sanctum')->postJson('/api/v1/billing/quotes', ['subtotal' => 5])->assertForbidden();

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/billing/quotes', ['client_workspace_id' => (string) $this->clientB->id, 'subtotal' => 5])->assertCreated();
        $this->assertSame(0, Quote::query()->where('client_workspace_id', $this->clientA->id)->where('subtotal', 5)->count());
    }

    public function test_a_quote_cannot_pair_a_project_with_a_different_client(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/billing/quotes', ['client_workspace_id' => (string) $this->clientA->id, 'project_id' => (string) $this->projectB->id, 'subtotal' => 5])
            ->assertStatus(422);
    }
}
