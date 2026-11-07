<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Models\PlanCatalogueVersion;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Subscriptions\Services\VatInvoiceReview;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRICING-VAT-002 — VAT is a term of the contract, captured at sale; the console manages it for new sales only, within the legal
 * treatments; and a read-only review flags the invoices an accountant must look at, rewriting none.
 */
final class VatTermsConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        $this->admin = User::create(['name' => 'Operator', 'email' => 'op@platform.test', 'password' => 'secret1234']);
        $this->admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
    }

    private function subscribe(string $code): Subscription
    {
        $tenant = Tenant::create(['name' => "T {$code}", 'slug' => 't-'.$code.'-'.Str::random(4), 'status' => 'active']);

        return app(SubscriptionService::class)->assignPlan($tenant, SubscriptionPlan::where('code', $code)->firstOrFail(), 'active', Carbon::now()->addDays(20));
    }

    private function edition(int $number): PlanCatalogueVersion
    {
        return PlanCatalogueVersion::query()->where('number', $number)->firstOrFail();
    }

    public function test_a_sale_captures_its_editions_vat_terms(): void
    {
        $team = $this->subscribe('team');
        $growth = $this->subscribe('growth');

        $this->assertSame(['basic_15', false], [$team->tax_treatment, $team->prices_include_vat]);
        $this->assertSame(['basic_15', true], [$growth->tax_treatment, $growth->prices_include_vat]);
    }

    public function test_a_console_change_applies_to_new_sales_and_re_taxes_nobody_already_subscribed(): void
    {
        $existing = $this->subscribe('team');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/tax/editions/{$this->edition(2)->id}", ['tax_treatment' => 'basic_15', 'prices_include_vat' => true, 'reason' => 'Show all-in prices'])
            ->assertOk()->assertJsonPath('data.edition.subscriptions_keeping_their_terms', 1);

        // The existing subscriber is charged on the terms they bought: 699 + 15%.
        app(SubscriptionCheckout::class)->chargeSubscription($existing->refresh());
        $this->assertSame('803.85', (string) SubscriptionPayment::query()->where('subscription_id', $existing->id)->value('amount'));

        // A new sale takes the new terms: 699 all-in.
        $this->assertSame([true], [$this->subscribe('team')->prices_include_vat]);
        $this->assertSame(1, AuditLog::query()->where('action', 'platform.tax.edition_updated')->where('reason', 'Show all-in prices')->count());
    }

    public function test_only_legal_treatments_and_only_an_admin(): void
    {
        $url = "/api/v1/admin/tax/editions/{$this->edition(2)->id}";
        $this->actingAs($this->admin, 'sanctum')->patchJson($url, ['tax_treatment' => 'historical_5', 'prices_include_vat' => false, 'reason' => 'Legacy rate'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->patchJson($url, ['tax_treatment' => '0.10', 'prices_include_vat' => false, 'reason' => 'Free rate'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/tax')->assertOk()
            ->assertJsonPath('data.treatments.0.key', 'basic_15')->assertJsonCount(1, 'data.treatments')
            ->assertJsonPath('data.needs_customer_eligibility', ['zero_rated', 'exempt', 'out_of_scope']);
        // Zero-rating depends on the customer, never on a whole edition.
        $this->actingAs($this->admin, 'sanctum')->patchJson($url, ['tax_treatment' => 'zero_rated', 'prices_include_vat' => false, 'reason' => 'Export'])->assertStatus(422);

        $owner = User::create(['name' => 'O', 'email' => 'o@t.test', 'password' => 'secret1234']);
        $this->actingAs($owner, 'sanctum')->patchJson($url, ['tax_treatment' => 'exempt', 'prices_include_vat' => false, 'reason' => 'Sneaky'])->assertForbidden();
        $this->assertSame('basic_15', $this->edition(2)->refresh()->tax_treatment);
    }

    public function test_the_review_flags_a_document_that_asks_for_more_than_was_charged_and_rewrites_nothing(): void
    {
        $sub = $this->subscribe('growth');
        $paymentId = (string) Str::uuid();
        DB::table('subscription_payments')->insert([
            'id' => $paymentId, 'tenant_id' => $sub->tenant_id, 'subscription_id' => $sub->id, 'purpose' => 'subscription', 'plan_code' => 'growth',
            'billing_interval' => 'monthly', 'provider' => 'sandbox', 'amount' => '9.00', 'currency' => 'USD', 'status' => 'paid',
            'idempotency_key' => 'legacy-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = (string) Str::uuid();
        DB::table('subscription_invoices')->insert([
            'id' => $invoiceId, 'number' => 'CH-LEGACY-1', 'tenant_id' => $sub->tenant_id, 'subscription_id' => $sub->id, 'subscription_payment_id' => $paymentId,
            'bill_to_name' => 'T', 'bill_to_email' => 't@t.test', 'currency' => 'USD', 'subtotal' => '9.00', 'discount_total' => '0.00', 'tax_total' => '1.35',
            'total' => '10.35', 'amount_paid' => '10.35', 'tax_treatment' => 'basic_15', 'tax_rate' => 0.15, 'status' => 'paid',
            'issued_at' => now(), 'due_at' => now(), 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $review = app(VatInvoiceReview::class)->run();

        $this->assertSame(1, $review['flagged']);
        $this->assertSame(['total_differs_from_charge', 'paid_amount_differs_from_collected'], $review['rows'][0]['issues']);
        $this->assertSame('10.35', (string) DB::table('subscription_invoices')->where('id', $invoiceId)->value('total'), 'nothing is rewritten');
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/tax/invoice-review')->assertOk()->assertJsonPath('data.flagged', 1);
        $this->artisan('subscriptions:vat-review')->expectsOutputToContain('flagged=1')->assertSuccessful();
    }
}
