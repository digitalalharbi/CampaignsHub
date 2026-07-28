<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Drive\Models\DriveFile;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestFile;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Client-facing content endpoints (files / campaigns / reports): a verified client sees ONLY its own workspace's
 * content — its request uploads + Drive metadata, its campaigns (client-safe, no cost fields), and only
 * client-audience reports that are actually shared. Cross-client is empty and internal-only reports are never
 * returned. Reuses the existing portal auth (X-Client-Token in non-production).
 */
final class ClientPortalContentTest extends TestCase
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

    /**
     * Provision a client: workspace + project + one owned request tied to a verified contact.
     *
     * @return array{workspace: ClientWorkspace, project: Project, request: ExternalRequest}
     */
    private function provisionClient(string $email, string $phone): array
    {
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C '.$email, 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'Proj '.$email, 'status' => 'active',
        ]);

        $request = ExternalRequest::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'REQ-2026-'.strtoupper(substr(md5($email), 0, 6)),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Client',
            'contact_email' => $email,
            'contact_phone' => $phone,
            'client_id' => $workspace->id,
            'journey_stage' => 'in_progress',
            'submitted_at' => now(),
        ]);

        return ['workspace' => $workspace, 'project' => $project, 'request' => $request];
    }

    private function addRequestFile(ExternalRequest $request, string $name, bool $clientVisible): RequestFile
    {
        return RequestFile::create([
            'request_id' => $request->id,
            'disk' => 'local',
            'path' => 'requests/'.Str::uuid().'/'.$name,
            'original_name' => $name,
            'mime' => 'application/pdf',
            'size' => 1024,
            'is_client_visible' => $clientVisible,
            'created_at' => now(),
        ]);
    }

    private function addDriveFileForWorkspace(ClientWorkspace $workspace, string $name): DriveFile
    {
        $link = DriveLink::create([
            'tenant_id' => $this->tenant->id, 'scope' => 'client', 'scope_id' => $workspace->id,
            'folder_id' => 'folder_'.uniqid(), 'folder_name' => 'Client Folder',
        ]);

        return DriveFile::create([
            'tenant_id' => $this->tenant->id, 'drive_link_id' => $link->id,
            'file_id' => 'file_'.uniqid(), 'name' => $name, 'mime' => 'image/png', 'size' => 2048,
            'web_view_link' => 'https://drive.example/'.$name, 'modified_time' => now(),
        ]);
    }

    private function addCampaign(array $client, string $name): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $client['project']->id,
            'client_workspace_id' => $client['workspace']->id, 'name' => $name,
            'objective' => 'conversions', 'status' => 'active', 'total_budget' => 50000, 'budget_currency' => 'SAR',
        ]);
    }

    private function addReport(array $client, string $name, string $audience, bool $shared): Report
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $client['project']->id,
            'name' => $name, 'type' => 'executive', 'audience' => $audience, 'status' => 'completed',
        ]);

        if ($shared) {
            ReportShare::create([
                'tenant_id' => $this->tenant->id, 'report_id' => $report->id,
                'token_hash' => hash('sha256', Str::random(48)), 'allow_download' => true,
            ]);
        }

        return $report;
    }

    /** Log into the portal via email OTP; return the raw dev token for the X-Client-Token header. */
    private function portalLogin(string $email): string
    {
        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => $email])->assertCreated();
        $verify = $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk();

        return $verify->json('data.dev_token');
    }

    /** @return array<string,string> */
    private function auth(string $token): array
    {
        return ['X-Client-Token' => $token];
    }

    public function test_client_sees_only_their_own_files(): void
    {
        $mine = $this->provisionClient('owner@x.test', '+966500000001');
        $theirs = $this->provisionClient('stranger@x.test', '+966500000002');

        $this->addRequestFile($mine['request'], 'my-brief.pdf', clientVisible: true);
        $this->addRequestFile($mine['request'], 'internal-only.pdf', clientVisible: false); // internal → hidden
        $this->addDriveFileForWorkspace($mine['workspace'], 'my-creative.png');

        $this->addRequestFile($theirs['request'], 'their-brief.pdf', clientVisible: true);
        $this->addDriveFileForWorkspace($theirs['workspace'], 'their-creative.png');

        $token = $this->portalLogin('owner@x.test');
        $res = $this->getJson('/api/v1/client/files', $this->auth($token))->assertOk();

        $names = array_column($res->json('data.files'), 'name');
        sort($names);
        $this->assertSame(['my-brief.pdf', 'my-creative.png'], $names);

        // Internal-only upload and the other client's files are never present.
        $this->assertStringNotContainsString('internal-only.pdf', $res->getContent());
        $this->assertStringNotContainsString('their-brief.pdf', $res->getContent());
        $this->assertStringNotContainsString('their-creative.png', $res->getContent());
        // No internal storage path is ever leaked.
        $this->assertStringNotContainsString('requests/', $res->getContent());
    }

    public function test_client_sees_only_their_own_campaigns_without_cost_fields(): void
    {
        $mine = $this->provisionClient('owner@x.test', '+966500000001');
        $theirs = $this->provisionClient('stranger@x.test', '+966500000002');

        $this->addCampaign($mine, 'My Q3 Campaign');
        $this->addCampaign($theirs, 'Their Q3 Campaign');

        $token = $this->portalLogin('owner@x.test');
        $res = $this->getJson('/api/v1/client/campaigns', $this->auth($token))->assertOk();

        $res->assertJsonCount(1, 'data.campaigns')
            ->assertJsonPath('data.campaigns.0.name', 'My Q3 Campaign')
            ->assertJsonPath('data.campaigns.0.status', 'active');

        $this->assertStringNotContainsString('Their Q3 Campaign', $res->getContent());
        // Cost fields must never appear in the client-safe shape.
        foreach (['total_budget', 'budget_currency', 'spend', 'revenue', 'roas', 'cpa', 'cpc', 'cpm'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $res->getContent());
        }
    }

    public function test_client_sees_only_shared_client_reports_and_never_internal(): void
    {
        $mine = $this->provisionClient('owner@x.test', '+966500000001');
        $theirs = $this->provisionClient('stranger@x.test', '+966500000002');

        $this->addReport($mine, 'My Client Report', audience: 'client', shared: true);
        $this->addReport($mine, 'My Internal Report', audience: 'internal', shared: true);  // internal → never
        $this->addReport($mine, 'My Unshared Report', audience: 'client', shared: false);   // not shared → hidden
        $this->addReport($theirs, 'Their Client Report', audience: 'client', shared: true); // other client

        $token = $this->portalLogin('owner@x.test');
        $res = $this->getJson('/api/v1/client/reports', $this->auth($token))->assertOk();

        $res->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.name', 'My Client Report')
            ->assertJsonPath('data.reports.0.audience', 'client')
            ->assertJsonPath('data.reports.0.share.shared', true);

        $this->assertStringNotContainsString('My Internal Report', $res->getContent());
        $this->assertStringNotContainsString('My Unshared Report', $res->getContent());
        $this->assertStringNotContainsString('Their Client Report', $res->getContent());
    }

    public function test_cross_client_content_is_empty_for_a_client_with_no_content(): void
    {
        $this->provisionClient('owner@x.test', '+966500000001');
        $theirs = $this->provisionClient('stranger@x.test', '+966500000002');

        // Give the OTHER client everything; the logged-in client owns none of it.
        $this->addRequestFile($theirs['request'], 'their-brief.pdf', clientVisible: true);
        $this->addDriveFileForWorkspace($theirs['workspace'], 'their-creative.png');
        $this->addCampaign($theirs, 'Their Campaign');
        $this->addReport($theirs, 'Their Report', audience: 'client', shared: true);

        $token = $this->portalLogin('owner@x.test');

        $this->getJson('/api/v1/client/files', $this->auth($token))->assertOk()->assertJsonCount(0, 'data.files');
        $this->getJson('/api/v1/client/campaigns', $this->auth($token))->assertOk()->assertJsonCount(0, 'data.campaigns');
        $this->getJson('/api/v1/client/reports', $this->auth($token))->assertOk()->assertJsonCount(0, 'data.reports');
    }

    public function test_content_endpoints_require_a_portal_session(): void
    {
        $this->getJson('/api/v1/client/files')->assertUnauthorized();
        $this->getJson('/api/v1/client/campaigns')->assertUnauthorized();
        $this->getJson('/api/v1/client/reports')->assertUnauthorized();
    }
}
