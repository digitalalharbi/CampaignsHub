<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * EMAIL-REPORT-READY-001 — the person who asked for a report is told when it is there, and when it is not.
 *
 * `report_ready` and `report_failed` were catalogue types nobody raised; the generation job raises them
 * now, addressed to the report's author, through the dispatcher that applies their choices.
 */
final class ReportReadyNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $this->author = User::create(['name' => 'Author', 'email' => 'a-'.uniqid().'@r.test', 'password' => 'secret123']);
        $this->grantMembership($this->author, $this->tenant);
    }

    private function report(): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Ramadan — monthly', 'type' => 'executive',
            'status' => 'draft', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => 'SAR', 'created_by' => $this->author->id,
        ]);
    }

    public function test_a_generated_report_tells_its_author_it_is_ready(): void
    {
        $report = $this->report();

        (new GenerateReportJob((string) $report->id))->handle(app(ReportGenerator::class));

        $this->assertSame('completed', $report->refresh()->status);
        $n = AppNotification::query()->where('type', 'report_ready')->first();
        $this->assertNotNull($n, 'the author was not told');
        $this->assertSame((int) $this->author->id, (int) $n->user_id);
        $this->assertStringContainsString('Ramadan — monthly', (string) $n->title);
        $this->assertSame('/app/reports', (string) $n->action_url);
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $n->id, 'channel' => 'email', 'status' => 'awaiting_credentials']);
    }

    public function test_a_failed_generation_tells_its_author_why(): void
    {
        $report = $this->report();

        // The queue's terminal hook, after the retries are spent — the one moment the failure speaks.
        (new GenerateReportJob((string) $report->id))->failed(new RuntimeException('no metrics for the period'));

        $this->assertSame('failed', $report->refresh()->status);
        $n = AppNotification::query()->where('type', 'report_failed')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('no metrics for the period', (string) $n->message);
        $this->assertNull(AppNotification::query()->where('type', 'report_ready')->first());
    }
}
