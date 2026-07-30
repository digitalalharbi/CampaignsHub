<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Services\AccountEntitlements;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the DemoAccountsSeeder lands real, resolvable data for the THREE experiences after a full seed:
 *   1) Operations Console  — owner@demo-agency has the PERSONAL (agency) nav.
 *   2) SaaS Workspace      — the demo-company tenant has the COMPANY nav + a Growth subscription with usage.
 *   3) Client Portal       — the verified customer sees a request + quote + invoice + thread + file + campaign
 *                            + report through the real portal scoping (pre-seeded X-Client-Token).
 */
final class DemoAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    /** The deterministic non-production portal token seeded by DemoAccountsSeeder. */
    private const CLIENT_TOKEN = 'demo-client-portal-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_operations_console_owner_has_the_personal_nav(): void
    {
        $tenant = Tenant::where('slug', 'demo-agency')->firstOrFail();
        $owner = User::where('email', 'owner@demo-agency.local')->firstOrFail();

        $this->assertTrue($owner->memberships()->where('tenant_id', $tenant->id)->exists());
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue($owner->hasPermission('campaigns.view'));

        $entitlements = app(AccountEntitlements::class);
        $this->assertSame('personal', $entitlements->workspaceKind($tenant));
        $nav = $entitlements->nav($tenant);
        // Personal (Operations Console) menu includes the agency tools.
        $this->assertContains('clients', $nav);
        $this->assertContains('requests', $nav);
        $this->assertContains('billing', $nav);
    }

    public function test_saas_workspace_tenant_has_company_nav_and_growth_subscription_with_usage(): void
    {
        $tenant = Tenant::where('slug', 'demo-company')->firstOrFail();

        $entitlements = app(AccountEntitlements::class);
        $this->assertSame('company', $entitlements->workspaceKind($tenant));
        $nav = $entitlements->nav($tenant);
        // Company (SaaS Workspace) menu: subscriptions, but NO agency tools.
        $this->assertContains('subscriptions', $nav);
        $this->assertNotContains('clients', $nav);
        $this->assertNotContains('requests', $nav);

        // Two verified company logins.
        foreach (['owner@demo-company.local', 'member@demo-company.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue($user->memberships()->where('tenant_id', $tenant->id)->exists());
            $this->assertNotNull($user->email_verified_at);
        }

        // Growth plan assigned, with visibly non-zero usage against its caps.
        $subscriptions = app(SubscriptionService::class);
        $this->assertSame('growth', $subscriptions->currentPlan($tenant)?->code);
        $this->assertGreaterThan(0, $subscriptions->usage($tenant, 'projects'));
        $this->assertGreaterThan(0, $subscriptions->usage($tenant, 'reports_per_month'));

        $summary = $subscriptions->usageSummary($tenant, ['projects', 'reports_per_month']);
        $this->assertSame(25, $summary['projects']['limit']);   // Growth cap
        $this->assertGreaterThan(0, $summary['projects']['used']);
        $this->assertNotNull($summary['projects']['remaining']);
    }

    public function test_client_portal_customer_sees_a_full_journey(): void
    {
        // The pre-seeded portal identity resolves the session directly (non-production X-Client-Token path).
        $headers = ['X-Client-Token' => self::CLIENT_TOKEN];

        $this->getJson('/api/v1/client/session', $headers)
            ->assertOk()->assertJsonPath('data.contact_email', 'customer@demo-client.local');

        // Requests — the mid-journey request is visible.
        $this->getJson('/api/v1/client/requests', $headers)->assertOk()
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.reference', 'REQ-DEMO-CLIENT-0001');

        // Quotes — the open (sent) quote + the approved one.
        $quotes = $this->getJson('/api/v1/client/quotes', $headers)->assertOk()->json('data.quotes');
        $this->assertGreaterThanOrEqual(2, count($quotes));
        $this->assertContains('sent', array_column($quotes, 'status'));
        $this->assertContains('approved', array_column($quotes, 'status'));

        // Invoices — one issued invoice, unpaid (the honest pay flow awaits provider credentials).
        $invoices = $this->getJson('/api/v1/client/invoices', $headers)->assertOk()->json('data.invoices');
        $this->assertCount(1, $invoices);
        $this->assertSame('issued', $invoices[0]['status']);
        $this->assertSame('unpaid', $invoices[0]['payment_status']);

        $pay = $this->postJson("/api/v1/client/invoices/{$invoices[0]['id']}/pay", [], $headers)->assertCreated();
        $pay->assertJsonPath('data.payment.payment_state', 'awaiting_provider_credentials')
            ->assertJsonPath('data.payment.status', 'pending');

        // Messages — one thread with the seeded exchange.
        $threads = $this->getJson('/api/v1/client/messages', $headers)->assertOk()->json('data.threads');
        $this->assertCount(1, $threads);
        $this->getJson("/api/v1/client/messages/{$threads[0]['id']}", $headers)->assertOk()
            ->assertJsonCount(3, 'data.messages');

        // Files — the client-visible request upload + the Drive reference.
        $files = $this->getJson('/api/v1/client/files', $headers)->assertOk()->json('data.files');
        $names = array_column($files, 'name');
        $this->assertContains('campaign-brief.pdf', $names);
        $this->assertContains('creative-final-v2.png', $names);

        // Campaigns — the linked campaign (client-safe, no cost fields).
        $campaigns = $this->getJson('/api/v1/client/campaigns', $headers)->assertOk()->json('data.campaigns');
        $this->assertCount(1, $campaigns);
        $this->assertSame('National Day Launch', $campaigns[0]['name']);

        // Reports — the shared client-audience report.
        $reports = $this->getJson('/api/v1/client/reports', $headers)->assertOk()->json('data.reports');
        $this->assertCount(1, $reports);
        $this->assertSame('client', $reports[0]['audience']);
        $this->assertTrue($reports[0]['share']['shared']);
    }

    public function test_seeder_is_idempotent_on_re_run(): void
    {
        // A second full seed must not duplicate rows or throw.
        $this->seed(DatabaseSeeder::class);

        $tenant = Tenant::where('slug', 'demo-company')->firstOrFail();
        app(TenantContext::class)->setTenantId((string) $tenant->id);

        $headers = ['X-Client-Token' => self::CLIENT_TOKEN];
        $this->getJson('/api/v1/client/invoices', $headers)->assertOk()->assertJsonCount(1, 'data.invoices');
        $this->getJson('/api/v1/client/messages', $headers)->assertOk()->assertJsonCount(1, 'data.threads');
    }
}
