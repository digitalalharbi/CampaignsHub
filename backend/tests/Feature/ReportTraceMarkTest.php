<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * IP-TRACE-001 — a leaked copy can be traced to the link it came from, and to nothing about a person.
 *
 * Every link is born with an opaque, unique reference. It rides the public page (when watermarked), the
 * print token behind the PDF and the manifest of the CSV and the spreadsheet, and the Owner who issued the
 * link can ask which link a reference belongs to — in their own project, with permission, and never across
 * tenants. The public payload carries no recipient, because the system never knew one.
 */
final class ReportTraceMarkTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Parity', 'slug' => 'parity-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Sales push', 'status' => 'active', 'objective' => 'sales',
        ]);
    }

    /** A completed client snapshot where each hidden section has a different reason. */
    private function snapshotReport(): Report
    {
        $data = [
            'period' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
            'currency' => 'SAR',
            'objective' => 'sales',
            'kpis' => ['spend' => 1200.0, 'conversions' => 30.0, 'impressions' => 90000.0, 'clicks' => 900.0],
            'timeseries' => [
                ['date' => '2026-08-01', 'spend' => 400.0], ['date' => '2026-08-02', 'spend' => 400.0], ['date' => '2026-08-03', 'spend' => 400.0],
            ],
            'platforms' => [
                ['provider' => 'meta', 'spend' => 700.0, 'conversions' => 20.0],
                ['provider' => 'snapchat', 'spend' => 500.0, 'conversions' => 10.0],
            ],
            // data_unavailable: no platform carries a budget.
            'budget' => [['provider' => 'meta', 'budget' => 0.0, 'spent' => 700.0]],
            'funnel' => [['stage' => 'impressions', 'label' => 'Impressions', 'reported' => true, 'count' => 90000]],
            // unsupported_by_provider_or_objective: no metric ranks ads honestly here.
            'ads' => [],
            'ads_roster' => [],
            'ads_absent_reason' => 'no_rankable_metric_for_this_objective',
            'recommendations' => [['title' => 'Shift budget to Meta', 'status' => 'approved']],
            'objective_leaders' => ['paths' => [['path' => 'direct_sales', 'comparable' => false]]],
            'objective_performance' => ['paths' => [['path' => 'direct_sales', 'spend' => 1200.0]]],
            'slides' => [
                ['id' => 'cover', 'type' => 'cover', 'order' => 1, 'visible' => true],
                ['id' => 'summary', 'type' => 'executive_summary', 'order' => 2, 'visible' => true],
                ['id' => 'cmp', 'type' => 'platform_comparison', 'order' => 3, 'visible' => true],
                ['id' => 'budget', 'type' => 'budget', 'order' => 4, 'visible' => true],
                ['id' => 'funnel', 'type' => 'funnel', 'order' => 5, 'visible' => true],
                ['id' => 'ads', 'type' => 'ads', 'order' => 6, 'visible' => true],
                ['id' => 'split', 'type' => 'objective_performance', 'order' => 7, 'visible' => true],
                ['id' => 'recs', 'type' => 'recommendations', 'order' => 8, 'visible' => true],
            ],
        ];
        $data['checksum'] = ExportReadinessGate::checksum($data);

        return Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'August', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR',
            'data' => $data,
            // disabled_by_operator: the operator switched the trends off.
            'section_settings' => ['sections' => ['trends' => false]],
        ]);
    }

    private function shareFor(Report $r, array $opts = []): array
    {
        return app(ShareService::class)->create($r, ['allow_download' => true] + $opts, null);
    }

    public function test_every_link_is_born_with_a_unique_well_formed_reference(): void
    {
        $report = $this->snapshotReport();
        [$a] = $this->shareFor($report);
        [$b] = $this->shareFor($report);

        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}$/', $a->trace_mark);
        $this->assertNotSame($a->trace_mark, $b->trace_mark);
        $this->assertStringNotContainsString((string) $report->getKey(), $a->trace_mark, 'derived from nothing about the report');
    }

    public function test_the_public_page_carries_the_mark_only_when_the_link_is_watermarked(): void
    {
        $report = $this->snapshotReport();
        [$marked, $rawMarked] = $this->shareFor($report, ['watermark' => true]);
        [, $rawPlain] = $this->shareFor($report, ['watermark' => false]);

        $this->assertSame($marked->trace_mark, $this->getJson("/api/v1/reports/shared/{$rawMarked}")->assertOk()->json('data.settings.trace_mark'));
        $this->assertNull($this->getJson("/api/v1/reports/shared/{$rawPlain}")->assertOk()->json('data.settings.trace_mark'));
    }

    public function test_the_public_payload_names_no_recipient_and_no_issuer(): void
    {
        $report = $this->snapshotReport();
        [, $raw] = $this->shareFor($report, ['watermark' => true]);

        $json = (string) json_encode($this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json());

        foreach (['created_by', 'token_hash', 'password_hash', 'tenant_id', 'recipient', 'email'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $json, $forbidden);
        }
    }

    public function test_the_csv_and_xlsx_manifests_carry_the_links_reference(): void
    {
        $report = $this->snapshotReport();
        [$share] = $this->shareFor($report);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $fresh = $share->report()->withoutGlobalScopes()->first();

        $csv = app(ReportExporter::class)->render($fresh, 'csv', $share);
        $this->assertStringContainsString('"Reference / المرجع",'.$share->trace_mark, $csv);

        $tmp = tempnam(sys_get_temp_dir(), 'trace_').'.xlsx';
        file_put_contents($tmp, app(ReportExporter::class)->render($fresh, 'xlsx', $share));
        $notes = IOFactory::load($tmp)->getSheetByName('Methodology & Notes')->toArray();
        @unlink($tmp);
        $this->assertContains($share->trace_mark, array_column($notes, 1));
    }

    public function test_a_file_with_no_link_carries_no_reference(): void
    {
        $report = $this->snapshotReport();
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $this->assertStringNotContainsString('Reference / المرجع', app(ReportExporter::class)->render($report->fresh(), 'csv'));
    }

    public function test_the_print_route_gets_the_mark_from_the_server_minted_context_only(): void
    {
        $report = $this->snapshotReport();
        [$share] = $this->shareFor($report, ['watermark' => true]);
        $token = Str::random(48);
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $report->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client',
            'watermark' => true, 'share_id' => (string) $share->getKey(), 'trace' => $share->trace_mark,
        ], 300);

        $this->assertSame($share->trace_mark, $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.trace'));

        // A caller cannot supply or change it through the query string.
        $this->assertSame($share->trace_mark, $this->getJson("/api/v1/reports/print/{$token}?trace=FORGED-00000")->assertOk()->json('data.trace'));
    }

    private function owner(): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'Issuer', 'email' => 'issuer-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    public function test_the_owner_can_trace_a_reference_to_its_link_in_their_own_project(): void
    {
        $this->seed(PermissionSeeder::class);
        $owner = $this->owner();
        $report = $this->snapshotReport();
        [$share] = $this->shareFor($report, ['watermark' => true]);
        $share->forceFill(['created_by' => $owner->id])->save();
        app(TenantContext::class)->forget();

        $url = fn (string $mark) => "/api/v1/projects/{$this->project->getKey()}/report-links/trace/{$mark}";
        $res = $this->actingAs($owner, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey());

        // Exact, without the hyphen, and in lower case — a reference read off a screenshot is rarely perfect.
        foreach ([$share->trace_mark, str_replace('-', '', $share->trace_mark), strtolower($share->trace_mark)] as $mark) {
            $res->getJson($url($mark))->assertOk()
                ->assertJsonPath('data.id', (string) $share->getKey())
                ->assertJsonPath('data.report_name', $report->name)
                ->assertJsonPath('data.created_by', 'Issuer');
        }

        $res->getJson($url('ZZZZZ-ZZZZZ'))->assertNotFound();
        $res->getJson($url('not-a-ref'))->assertStatus(422);
    }

    public function test_a_reference_does_not_resolve_in_another_project_or_another_tenant_or_without_permission(): void
    {
        $this->seed(PermissionSeeder::class);
        $owner = $this->owner();
        $report = $this->snapshotReport();
        [$share] = $this->shareFor($report);
        app(TenantContext::class)->forget();

        // Another tenant's owner.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $other->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $other->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $otherProject = Project::create(['tenant_id' => $other->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P2', 'status' => 'active']);
        $role = Role::create(['tenant_id' => $other->getKey(), 'name' => 'Owner', 'slug' => 'o2']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $other);
        $stranger->assignRole($role);
        app(TenantContext::class)->forget();

        $this->actingAs($stranger, 'sanctum')->withHeader('X-Project-Id', (string) $otherProject->getKey())
            ->getJson("/api/v1/projects/{$otherProject->getKey()}/report-links/trace/{$share->trace_mark}")->assertNotFound();

        // The owner, asking from a project the link is not in, or without the sharing permission.
        $this->actingAs($owner, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey())
            ->getJson("/api/v1/projects/{$this->project->getKey()}/report-links/trace/{$share->trace_mark}")->assertOk();
        $owner->roles()->detach();
        $this->actingAs($owner->fresh(), 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey())
            ->getJson("/api/v1/projects/{$this->project->getKey()}/report-links/trace/{$share->trace_mark}")->assertForbidden();
    }

    /**
     * A link is looked up THROUGH its report: presenting another report's link id under this report's
     * address is a 404, for every action that takes both ids (revoke, renew, edit, analytics, access log).
     */
    public function test_a_link_cannot_be_acted_on_through_another_reports_address(): void
    {
        $this->seed(PermissionSeeder::class);
        $owner = $this->owner();
        $reportA = $this->snapshotReport();
        $reportB = $this->snapshotReport();
        [$shareB] = $this->shareFor($reportB);
        app(TenantContext::class)->forget();

        $as = $this->actingAs($owner, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey());
        $base = "/api/v1/projects/{$this->project->getKey()}/reports/{$reportA->getKey()}/shares/{$shareB->getKey()}";

        $as->postJson("{$base}/revoke")->assertNotFound();
        $as->postJson("{$base}/renew", ['expires_at' => now()->addDays(5)->toDateString()])->assertNotFound();
        $as->patchJson($base, ['watermark' => false])->assertNotFound();
        $as->getJson("{$base}/analytics")->assertNotFound();
        $as->getJson("{$base}/logs")->assertNotFound();

        $this->assertNull($shareB->fresh()->revoked_at, 'the link was not touched');

        // Through its own report the same calls work.
        $own = "/api/v1/projects/{$this->project->getKey()}/reports/{$reportB->getKey()}/shares/{$shareB->getKey()}";
        $as->getJson("{$own}/logs")->assertOk();
        $as->postJson("{$own}/revoke")->assertOk();
        $this->assertNotNull($shareB->fresh()->revoked_at);
    }
}
