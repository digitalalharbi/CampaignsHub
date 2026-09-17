<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ClientReportContentValidator;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Audience isolation: internal reports cannot be shared; the client print response carries no internal data. */
final class ReportAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        // Scope is request-scoped since ADR 0002; this test creates rows directly between
        // requests, so it holds its tenant for the whole test rather than for one call.
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    private function report(string $audience): Report
    {
        return Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'audience' => $audience,
            'status' => 'completed', 'currency' => 'SAR',
            'data' => [
                'currency' => 'SAR', 'checksum' => str_repeat('a', 40), 'tenant_id' => $this->tenant->id,
                'kpis' => ['spend' => 100, 'revenue' => 400, 'conversions' => 5, 'roas' => 4.0, 'cpa' => 20.0],
                'platforms' => [['provider' => 'meta', 'spend' => 100, 'revenue' => 400, 'conversions' => 5]],
                'campaigns' => [['campaign_name' => 'Meta — Lead Gen (burner)', 'provider' => 'meta', 'spend' => 100]],
                'recommendations' => [['id' => 'x', 'title' => 'زيادة الميزانية', 'status' => 'draft', 'type' => 'recommendation']],
            ],
        ]);
    }

    public function test_internal_report_cannot_be_shared(): void
    {
        Sanctum::actingAs($this->owner);
        $internal = $this->report('internal');
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$internal->id}/shares", [])
            ->assertStatus(422);

        // A client report can be shared.
        $client = $this->report('client');
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$client->id}/shares", [])
            ->assertStatus(201);
    }

    /**
     * A LIVE link is client-facing by construction, and that is a decision rather than an oversight.
     *
     * Owner defect row 96 says «Client and Internal do not differ where configured». For a generated
     * snapshot they differ and the cases around this one prove it. For a LIVE link there is nothing
     * to configure: `LiveReportBuilderController` pins the audience to `client`, and the product was
     * read before that was called a bug.
     *
     * Three things already say a live link is an outward document. `ReportShareController` refuses
     * 422 to share an INTERNAL report at all — asserted directly above. `LiveReportService` applies
     * `ClientEntityBoundary` unconditionally, so campaign names and our primary keys are stripped
     * from every live payload whatever audience it claims. And the Owner removed campaign identity
     * from client-facing reports and asked that it never come back.
     *
     * So an «internal live link» is a contradiction the product forbids one layer up. Building one
     * would mean either bypassing the entity boundary — restoring exactly what the Owner removed —
     * or shipping an «internal» form that renders identically to the client one, which is the
     * placebo control this closure exists to remove. The axis stays absent, deliberately, and this
     * pins it so that adding one is a decision somebody takes on purpose rather than by filling in
     * a form field that looks unfinished.
     */
    public function test_a_live_link_is_client_facing_and_has_no_internal_form(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/v1/projects/{$this->project->id}/reports/live", [
            'name' => 'Live', 'from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString(),
            'audience' => 'internal',
        ]);

        $report = Report::withoutGlobalScopes()->where('name', 'Live')->first();

        if ($response->status() === 201) {
            $this->assertSame(
                'client',
                $report?->audience,
                'a live link was created for an internal audience — its payload is stripped by '.
                'ClientEntityBoundary regardless, so it would be an internal label over a client document',
            );
        }

        // And the rule this is consistent with: an internal report cannot be shared at all.
        $internal = $this->report('internal');
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$internal->id}/shares", [])
            ->assertStatus(422);
    }

    public function test_authenticated_client_export_is_filtered_but_internal_is_full(): void
    {
        $exporter = app(ReportExporter::class);

        // Client CSV: internal marker sanitised, draft recommendation dropped — even for an admin export.
        $clientCsv = $exporter->render($this->report('client'), 'csv');
        $this->assertStringNotContainsStringIgnoringCase('burner', $clientCsv);

        // Internal CSV: full snapshot, internal campaign name retained for the team.
        $internalCsv = $exporter->render($this->report('internal'), 'csv');
        $this->assertStringContainsStringIgnoringCase('burner', $internalCsv);
    }

    /**
     * The FORM reaches the exported file, not just the slide list — Owner defect row 96.
     *
     * A report's composition is decided when it is generated: `ReportTemplateEngine::defaultConfig`
     * omits the per-platform, funnel, campaign and data-quality slides for a summary. The exporter
     * dropped a section's data only when its slide was present and explicitly `visible => false`,
     * and a summary does not mark those slides invisible — it simply does not have them. So nothing
     * was dropped and both forms exported the same document.
     *
     * Measured before the fix on a generated report with real figures (38 KPIs, 4 platforms, 6 funnel
     * stages): the executive-summary CSV and the detailed CSV were byte-identical apart from a
     * one-second difference in their own «generated at» stamp — 3411 bytes and 61 lines each. The
     * operator's choice reached the slide list and stopped there, so the file a client receives was
     * the same document under two names.
     *
     * Asserted on a section that only the detailed form carries, rather than on a byte count: a size
     * comparison would pass for any change that made one file longer, including a worse one.
     */
    public function test_a_summary_and_a_detailed_report_do_not_export_the_same_file(): void
    {
        $exporter = app(ReportExporter::class);

        $detailed = $this->report('internal');
        $detailed->forceFill(['form' => 'detailed', 'config' => ['slides' => [
            ['type' => 'cover', 'visible' => true],
            ['type' => 'campaigns', 'visible' => true],
        ]]])->saveQuietly();

        $summary = $this->report('internal');
        $summary->forceFill(['form' => 'executive_summary', 'config' => ['slides' => [
            // A summary OMITS the campaigns slide — it does not mark it invisible.
            ['type' => 'cover', 'visible' => true],
        ]]])->saveQuietly();

        $this->assertStringContainsString(
            'Campaigns',
            $exporter->render($detailed, 'csv'),
            'the detailed export lost a section its own composition contains',
        );

        $this->assertStringNotContainsString(
            'Campaigns',
            $exporter->render($summary, 'csv'),
            'the summary exported a section its own composition does not contain — '.
            'the operator chose a summary and the client received the detailed document',
        );
    }

    /**
     * And a client's file carries nothing about our plumbing.
     *
     * The manifest appended to every export wrote «Data source: daily_metrics» — the name of one of
     * our database tables — into the file a client downloads and keeps. Handoff §13: do not expose
     * implementation internals. It stays for an INTERNAL export, where an operator reconciling a
     * figure needs to know which table and which window produced it.
     */
    public function test_a_client_export_carries_no_database_table_name(): void
    {
        $exporter = app(ReportExporter::class);

        /*
         * Stamped explicitly so the AUDIENCE is the only thing that differs between the two files.
         *
         * `reports.data_source` is `NOT NULL DEFAULT 'daily_metrics'`, and a model just created in
         * memory has not read that default back — so without this both exports would omit the row
         * and the case would pass for having nothing to leak rather than for withholding it.
         */
        $client = $this->report('client');
        $client->forceFill(['data_source' => 'daily_metrics'])->saveQuietly();
        $internal = $this->report('internal');
        $internal->forceFill(['data_source' => 'daily_metrics'])->saveQuietly();

        $this->assertStringNotContainsString('daily_metrics', $exporter->render($client, 'csv'));
        $this->assertStringContainsString('daily_metrics', $exporter->render($internal, 'csv'));
    }

    public function test_xlsx_sheets_differ_by_audience(): void
    {
        $exporter = app(ReportExporter::class);
        $sheetNames = function (string $xlsx): array {
            $tmp = tempnam(sys_get_temp_dir(), 'aud_').'.xlsx';
            file_put_contents($tmp, $xlsx);
            $names = IOFactory::load($tmp)->getSheetNames();
            @unlink($tmp);

            return $names;
        };

        $client = $sheetNames($exporter->render($this->report('client'), 'xlsx'));
        $internal = $sheetNames($exporter->render($this->report('internal'), 'xlsx'));

        // Client has next-steps but NOT internal diagnostics sheets.
        $this->assertContains('Next Steps', $client);
        $this->assertNotContains('Data Quality', $client);
        $this->assertNotContains('Raw Metrics', $client);
        $this->assertNotContains('All Recommendations', $client);
        // Internal has the diagnostic sheets.
        $this->assertContains('Data Quality', $internal);
        $this->assertContains('Raw Metrics', $internal);
        $this->assertContains('All Recommendations', $internal);
    }

    public function test_delivery_guard_blocks_internal_to_external_recipient(): void
    {
        Sanctum::actingAs($this->owner);
        $internal = $this->report('internal');

        // External/client email is blocked.
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$internal->id}/send", ['recipients' => ['client@external.test']])
            ->assertStatus(422);

        // An internal team member (same tenant) is allowed.
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$internal->id}/send", ['recipients' => [$this->owner->email]])
            ->assertOk();

        // A client report can go to anyone.
        $client = $this->report('client');
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$client->id}/send", ['recipients' => ['client@external.test']])
            ->assertOk();
    }

    public function test_client_print_response_has_no_internal_data(): void
    {
        $client = $this->report('client');
        $token = 'clienttok';
        Cache::put('report-print:'.hash('sha256', $token), ['report_id' => (string) $client->id, 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client'], 300);

        $res = $this->getJson("/api/v1/reports/print/{$token}")->assertOk();
        $body = $res->json('data.data');

        // Internal fields removed from the client body; draft rec dropped; internal name sanitised.
        $this->assertArrayNotHasKey('checksum', $body);
        $this->assertArrayNotHasKey('tenant_id', $body);
        // With the draft dropped nothing is left, so the section is absent rather than an empty list.
        $this->assertCount(0, $body['recommendations'] ?? []);
        $this->assertStringNotContainsStringIgnoringCase('burner', json_encode($body, JSON_UNESCAPED_UNICODE));
        // Content validator confirms a clean client body.
        $this->assertTrue(app(ClientReportContentValidator::class)->passes($body));
    }
}
