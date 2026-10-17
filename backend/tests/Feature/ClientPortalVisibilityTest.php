<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\PortalVisibility;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CP-VISIBILITY-001 and CP-WRITE-DENY-001 — the agency decides what a client's portal shows, and the
 * SERVER enforces it: a switched-off section is refused or absent from the response, not hidden by a screen.
 */
final class ClientPortalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /** @return array{workspace: ClientWorkspace, project: Project} */
    private function client(string $email, string $phone, array $settings = []): array
    {
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C '.$email, 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active', 'settings' => $settings,
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
        ExternalRequest::create([
            'tenant_id' => $this->tenant->id, 'reference' => 'REQ-2026-'.strtoupper(substr(md5($email.uniqid()), 0, 6)),
            'type_id' => RequestType::query()->firstOrFail()->id, 'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Client', 'contact_email' => $email, 'contact_phone' => $phone,
            'client_id' => $workspace->id, 'journey_stage' => 'in_progress', 'submitted_at' => now(),
        ]);

        return ['workspace' => $workspace, 'project' => $project];
    }

    private function campaign(array $client, string $name): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $client['project']->id,
            'client_workspace_id' => $client['workspace']->id, 'name' => $name,
            'objective' => 'conversions', 'status' => 'active', 'total_budget' => 5000, 'budget_currency' => 'SAR',
        ]);
    }

    private function login(string $email): array
    {
        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => $email])->assertCreated();
        $verify = $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'), 'code' => $start->json('data.dev_code'),
        ])->assertOk();

        return ['X-Client-Token' => $verify->json('data.dev_token')];
    }

    public function test_a_managed_workspace_defaults_to_seeing_the_business_picture_and_nothing_with_money_or_writes(): void
    {
        $w = $this->client('a@x.test', '+966500000001')['workspace'];

        $v = PortalVisibility::visibilityFor($w);
        foreach (['dashboard', 'projects', 'campaigns', 'content', 'reports', 'recommendations', 'requests', 'invoices', 'messages', 'files'] as $on) {
            $this->assertTrue($v[$on], "{$on} is on by default for a managed client");
        }
        foreach (['spend', 'revenue', 'platform_evidence', 'ad_account_names'] as $off) {
            $this->assertFalse($v[$off], "{$off} is the agency's decision, off by default");
        }

        $c = PortalVisibility::capabilitiesFor($w);
        foreach (['integration_admin', 'campaign_writes', 'pause_launch', 'budget_writes', 'team_admin', 'internal_notes', 'margin_profit'] as $denied) {
            $this->assertFalse($c[$denied], "{$denied} is denied to a managed client");
        }
    }

    public function test_the_agency_can_switch_a_section_off_and_the_server_refuses_it(): void
    {
        $a = $this->client('a@x.test', '+966500000001', ['portal' => ['visibility' => ['invoices' => false, 'campaigns' => false, 'messages' => false]]]);
        $this->campaign($a, 'Hidden Q3');
        $h = $this->login('a@x.test');

        $this->getJson('/api/v1/client/campaigns', $h)->assertOk()->assertJsonCount(0, 'data.campaigns');
        $this->assertStringNotContainsString('Hidden Q3', $this->getJson('/api/v1/client/campaigns', $h)->getContent());
        $this->getJson('/api/v1/client/invoices', $h)->assertOk()->assertJsonCount(0, 'data.invoices');
        $this->getJson('/api/v1/client/messages', $h)->assertOk()->assertJsonCount(0, 'data.threads');
        $this->postJson('/api/v1/client/messages', ['subject' => 'x', 'body' => 'y'], $h)->assertStatus(422);
    }

    public function test_one_workspaces_setting_never_changes_another(): void
    {
        $a = $this->client('a@x.test', '+966500000001', ['portal' => ['visibility' => ['campaigns' => false]]]);
        $b = $this->client('b@x.test', '+966500000002');
        $this->campaign($a, 'A camp');
        $this->campaign($b, 'B camp');

        $this->getJson('/api/v1/client/campaigns', $this->login('a@x.test'))->assertJsonCount(0, 'data.campaigns');
        $this->getJson('/api/v1/client/campaigns', $this->login('b@x.test'))->assertJsonCount(1, 'data.campaigns')->assertJsonPath('data.campaigns.0.name', 'B camp');
    }

    public function test_a_hidden_campaign_name_is_absent_not_blanked_by_the_screen(): void
    {
        $a = $this->client('a@x.test', '+966500000001', ['portal' => ['visibility' => ['campaign_names' => false]]]);
        $this->campaign($a, 'Secret Name');

        $res = $this->getJson('/api/v1/client/campaigns', $this->login('a@x.test'))->assertOk();
        $this->assertStringNotContainsString('Secret Name', $res->getContent());
        $res->assertJsonPath('data.campaigns.0.name', null)->assertJsonPath('data.campaigns.0.objective', 'conversions');
    }

    public function test_money_appears_only_when_the_agency_switches_it_on(): void
    {
        $off = $this->client('a@x.test', '+966500000001');
        $on = $this->client('b@x.test', '+966500000002', ['portal' => ['visibility' => ['spend' => true]]]);
        $this->campaign($off, 'Off');
        $this->campaign($on, 'On');

        $this->assertStringNotContainsString('"spend"', $this->getJson('/api/v1/client/campaigns', $this->login('a@x.test'))->getContent());
        $res = $this->getJson('/api/v1/client/campaigns', $this->login('b@x.test'))->assertOk();
        $this->assertArrayHasKey('spend', $res->json('data.campaigns.0'));
        $this->assertSame('USD', $res->json('data.campaigns.0.currency'));
    }

    public function test_hidden_requests_are_not_listed_or_readable(): void
    {
        $a = $this->client('a@x.test', '+966500000001', ['portal' => ['visibility' => ['requests' => false]]]);
        $h = $this->login('a@x.test');
        $reference = ExternalRequest::query()->where('client_id', $a['workspace']->id)->value('reference');

        $this->getJson('/api/v1/client/requests', $h)->assertOk()->assertJsonCount(0, 'data.requests');
        $this->getJson("/api/v1/client/requests/{$reference}", $h)->assertNotFound();
    }

    public function test_the_visibility_endpoint_reports_the_effective_map_and_capabilities_per_space(): void
    {
        $this->client('a@x.test', '+966500000001', ['portal' => ['visibility' => ['reports' => false], 'capabilities' => ['campaign_writes' => true, 'margin_profit' => true]]]);

        $res = $this->getJson('/api/v1/client/visibility', $this->login('a@x.test'))->assertOk();

        $res->assertJsonPath('data.spaces.0.mode', 'managed')
            ->assertJsonPath('data.spaces.0.visibility.reports', false)
            ->assertJsonPath('data.spaces.0.visibility.invoices', true)
            ->assertJsonPath('data.spaces.0.capabilities.campaign_writes', true)
            ->assertJsonPath('data.spaces.0.capabilities.margin_profit', false);
    }

    public function test_the_portfolio_overview_is_scoped_to_one_space_and_money_is_opt_in(): void
    {
        $a = $this->client('a@x.test', '+966500000001');
        $b = $this->client('b@x.test', '+966500000002', ['portal' => ['visibility' => ['spend' => true]]]);
        $a['project']->update(['name' => 'Alpha Brand']);
        $b['project']->update(['name' => 'Bravo Brand']);
        $this->campaign($a, 'A1');
        $this->campaign($b, 'B1');

        $resA = $this->getJson('/api/v1/client/overview', $this->login('a@x.test'))->assertOk();
        $resA->assertJsonPath('data.space.slug', $a['workspace']->slug)
            ->assertJsonPath('data.campaigns.total', 1)
            ->assertJsonPath('data.projects.0.name', 'Alpha Brand');
        $this->assertStringNotContainsString('Bravo Brand', $resA->getContent());
        $this->assertArrayNotHasKey('spend', $resA->json('data.kpis'), 'money is absent unless the agency switches it on');
        $this->assertArrayNotHasKey('roas', $resA->json('data.kpis'));

        $resB = $this->getJson('/api/v1/client/overview', $this->login('b@x.test'))->assertOk();
        $this->assertArrayHasKey('spend', $resB->json('data.kpis'));
        $this->assertSame('USD', $resB->json('data.currency'));
        $this->assertArrayNotHasKey('roas', $resB->json('data.kpis'), 'ROAS needs both spend and revenue switched on');
    }

    public function test_the_overview_is_refused_when_the_dashboard_is_off_and_never_merges_two_spaces(): void
    {
        $this->client('a@x.test', '+966500000001', ['portal' => ['visibility' => ['dashboard' => false]]]);
        $this->getJson('/api/v1/client/overview', $this->login('a@x.test'))->assertForbidden();

        // One contact on two clients: no merged portfolio, a choice is required.
        $first = $this->client('both@x.test', '+966500000003');
        $second = $this->client('both@x.test', '+966500000003');
        $this->getJson('/api/v1/client/overview', $this->login('both@x.test'))->assertStatus(422);
        $this->assertNotSame($first['workspace']->id, $second['workspace']->id);
    }

    public function test_a_client_slug_can_never_equal_a_portal_page(): void
    {
        foreach (['invoices', 'Requests', 'reports'] as $reserved) {
            $w = ClientWorkspace::create([
                'tenant_id' => $this->tenant->id, 'name' => 'X', 'slug' => $reserved,
                'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
            ]);
            $this->assertSame($reserved.'-client', $w->slug);
        }
    }

    public function test_a_client_never_sees_an_invoice_before_it_is_issued_and_may_download_it_after(): void
    {
        $a = $this->client('a@x.test', '+966500000001');
        $make = fn (string $status, string $number) => Invoice::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $a['workspace']->id, 'number' => $number, 'currency' => 'SAR',
            'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'amount_paid' => 0, 'status' => $status,
        ]);
        $draft = $make('draft', 'INV-D-1');
        $reviewed = $make('reviewed', 'INV-R-1');
        $issued = $make('issued', 'INV-I-1');
        $h = $this->login('a@x.test');

        $numbers = array_column($this->getJson('/api/v1/client/invoices', $h)->assertOk()->json('data.invoices'), 'number');
        $this->assertSame(['INV-I-1'], $numbers);
        $this->getJson("/api/v1/client/invoices/{$draft->id}", $h)->assertNotFound();
        $this->getJson("/api/v1/client/invoices/{$reviewed->id}/pdf", $h)->assertNotFound();
        $this->getJson("/api/v1/client/invoices/{$issued->id}", $h)->assertOk();
    }
}
