<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Services\AccountEntitlements;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the DemoAccountsSeeder lands real, resolvable data for the THREE experiences after a full seed:
 *   1) Agency portal       — owner@demo-agency holds an AGENCY membership and sees the agency's sections.
 *   2) Advertiser portal   — the demo-company tenant gets the ADVERTISER nav + a Growth subscription with usage.
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

    public function test_the_demo_agency_owner_lands_in_the_agency_portal(): void
    {
        $tenant = Tenant::where('slug', 'demo-agency')->firstOrFail();
        $owner = User::where('email', 'owner@demo-agency.local')->firstOrFail();

        $this->assertTrue($owner->memberships()->where('tenant_id', $tenant->id)->exists());
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue($owner->hasPermission('campaigns.view'));

        $entitlements = app(AccountEntitlements::class);

        /*
         * The demo agency is seeded into the AGENCY portal, and that — not its account type — is
         * what puts the agency tools in front of it (REG-001). Asking for the same tenant's
         * advertiser nav must produce a different menu, which is the whole claim: one workspace,
         * two portals, two surfaces.
         */
        $agencyNav = $entitlements->nav($tenant, Portal::Agency);
        $this->assertContains('clients', $agencyNav);
        $this->assertContains('requests', $agencyNav);
        $this->assertContains('billing', $agencyNav);

        $advertiserNav = $entitlements->nav($tenant, Portal::App);
        $this->assertNotContains('clients', $advertiserNav);
        $this->assertNotContains('requests', $advertiserNav);

        // The owner's membership is genuinely the agency one, so the seeded demo opens where the
        // seeder's own instructions say it does.
        $this->assertSame(
            Portal::Agency,
            $owner->memberships()->where('tenant_id', $tenant->id)->firstOrFail()->portal,
        );
    }

    public function test_the_demo_company_gets_the_advertiser_nav_and_a_growth_subscription(): void
    {
        $tenant = Tenant::where('slug', 'demo-company')->firstOrFail();

        $entitlements = app(AccountEntitlements::class);
        $nav = $entitlements->nav($tenant, Portal::App);
        // The advertiser portal: the workspace's own subscription, and no agency tools.
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
