<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\ClientBillingProfile;
use App\Domains\Billing\Services\ClientInvoiceService;
use App\Domains\Billing\Services\InvoicePdf;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * INV-PDF-001 — the invoice document prints what the invoice and its frozen snapshot say: identities,
 * period, lines, VAT, total and payment details, in Arabic or English, with a marker while it is a draft.
 */
final class InvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(string $status = 'issued')
    {
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $workspace = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'Razzah Avenue', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'Store', 'status' => 'active']);
        $profile = ClientBillingProfile::create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'fee_model' => 'fixed', 'fixed_amount' => 1000,
            'currency' => 'SAR', 'tax_treatment' => 'basic_15', 'due_days' => 7,
            'issuer' => ['legal_name' => 'Smart Code Agency', 'vat_number' => '300000000000003', 'address' => 'Riyadh'],
            'bill_to' => ['legal_name' => 'Razzah Avenue Trading Co.', 'vat_number' => '310000000000003'],
            'payment_details' => ['bank' => 'Al Rajhi', 'beneficiary' => 'Smart Code Agency', 'iban' => 'SA0380000000608010167519'],
            'notes' => 'Payable by bank transfer.',
        ]);
        $service = app(ClientInvoiceService::class);
        $invoice = $service->draftFor($profile, Carbon::parse('2026-10-11'), Carbon::parse('2026-10-17'));

        if ($status !== 'draft') {
            $owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
            $service->review($invoice, $owner);
            $invoice = $service->issue($invoice->fresh());
        }

        return $invoice->fresh();
    }

    public function test_an_issued_invoice_prints_every_field_a_tax_invoice_needs_in_arabic(): void
    {
        $invoice = $this->invoice();
        $html = app(InvoicePdf::class)->html($invoice, 'ar');

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('فاتورة ضريبية', $html);
        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString('Smart Code Agency', $html);
        $this->assertStringContainsString('300000000000003', $html);
        $this->assertStringContainsString('Razzah Avenue Trading Co.', $html);
        $this->assertStringContainsString('2026-10-11 → 2026-10-17', $html);
        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringContainsString('150.00', $html);
        $this->assertStringContainsString('1,150.00 SAR', $html);
        $this->assertStringContainsString('(15%)', $html);
        $this->assertStringContainsString('SA0380000000608010167519', $html);
        $this->assertStringContainsString('Payable by bank transfer.', $html);
        $this->assertStringNotContainsString('class="mark"', $html, 'an issued invoice carries no draft marker');
    }

    public function test_the_english_document_is_left_to_right_and_a_draft_says_so(): void
    {
        $html = app(InvoicePdf::class)->html($this->invoice('draft'), 'en');

        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('Tax invoice', $html);
        $this->assertStringContainsString('class="mark">DRAFT', $html);
    }

    public function test_it_prints_the_frozen_snapshot_not_the_live_profile(): void
    {
        $invoice = $this->invoice();
        ClientBillingProfile::query()->update(['payment_details' => ['iban' => 'CHANGED-AFTER-ISSUE'], 'notes' => 'changed']);

        $html = app(InvoicePdf::class)->html($invoice->fresh(), 'en');

        $this->assertStringContainsString('SA0380000000608010167519', $html);
        $this->assertStringNotContainsString('CHANGED-AFTER-ISSUE', $html);
    }

    public function test_a_voided_invoice_is_marked_and_a_part_paid_one_shows_the_balance(): void
    {
        $invoice = $this->invoice();
        app(ClientInvoiceService::class)->recordPayment($invoice, 150.0, User::query()->firstOrFail());
        $html = app(InvoicePdf::class)->html($invoice->fresh(), 'en');
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('1,000.00 SAR', $html);

        $invoice->fresh()->update(['status' => 'void']);
        $this->assertStringContainsString('class="mark">VOID', app(InvoicePdf::class)->html($invoice->fresh(), 'en'));
    }

    public function test_the_pdf_is_a_real_file_when_the_renderer_is_enabled(): void
    {
        if (! config('reports.chromium.enabled') || ! is_dir(base_path('../frontend/node_modules/playwright-core'))) {
            $this->markTestSkipped('Chromium PDF rendering is not available in this environment (not enabled, or playwright-core is not installed).');
        }

        $bytes = app(InvoicePdf::class)->render($this->invoice(), 'ar');

        $this->assertStringStartsWith('%PDF', $bytes);
    }
}
