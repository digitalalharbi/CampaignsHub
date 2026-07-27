<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Services\ScheduledReportDispatcher;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Scheduled reports: due schedules generate a snapshot + honest delivery ledger; internal never to client. */
final class ScheduledReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // report generation is queued — don't run the engine here
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        User::create(['tenant_id' => $this->tenant->id, 'name' => 'Staff', 'email' => 'staff@a.test', 'password' => Hash::make('secret1234')]);
    }

    private function schedule(array $over = []): ReportSchedule
    {
        $s = new ReportSchedule;
        $s->forceFill(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Weekly Exec', 'type' => 'executive', 'frequency' => 'weekly', 'day' => 'sunday',
            'time' => '08:00', 'timezone' => 'Asia/Riyadh', 'audience' => 'client', 'language' => 'ar',
            'formats' => ['pdf'], 'recipients' => [['email' => 'client@ext.test', 'name' => 'Client']],
            'active' => true, 'next_run_at' => Carbon::now()->subHour(),
        ], $over))->save();

        return $s;
    }

    public function test_due_schedule_generates_a_report_and_honest_deliveries(): void
    {
        $s = $this->schedule();
        $dispatched = app(ScheduledReportDispatcher::class)->dispatchDue();

        $this->assertSame(1, $dispatched);
        // A snapshot report was created + generation queued (never faked as done).
        $this->assertSame(1, Report::where('project_id', $this->project->id)->count());
        Queue::assertPushed(GenerateReportJob::class);
        // Honest delivery: awaiting provider credentials, never "sent".
        $this->assertDatabaseHas('report_deliveries', ['schedule_id' => $s->id, 'recipient' => 'client@ext.test', 'format' => 'pdf', 'status' => 'awaiting_provider_credentials']);
        $this->assertDatabaseMissing('report_deliveries', ['status' => 'sent']);
        // Schedule advanced.
        $s->refresh();
        $this->assertNotNull($s->last_run_at);
        $this->assertTrue($s->next_run_at->isFuture());
    }

    public function test_internal_report_is_suppressed_for_external_recipients(): void
    {
        $this->schedule(['audience' => 'internal', 'recipients' => [['email' => 'client@ext.test']]]);
        app(ScheduledReportDispatcher::class)->dispatchDue();
        // An internal report to an external email is suppressed — never delivered.
        $this->assertDatabaseHas('report_deliveries', ['recipient' => 'client@ext.test', 'status' => 'suppressed']);
    }

    public function test_internal_report_to_a_tenant_user_is_queued_not_suppressed(): void
    {
        $this->schedule(['audience' => 'internal', 'recipients' => [['email' => 'staff@a.test']]]);
        app(ScheduledReportDispatcher::class)->dispatchDue();
        $this->assertDatabaseHas('report_deliveries', ['recipient' => 'staff@a.test', 'status' => 'awaiting_provider_credentials']);
    }

    public function test_not_due_and_inactive_schedules_are_skipped(): void
    {
        $this->schedule(['next_run_at' => Carbon::now()->addDay()]); // future
        $this->schedule(['active' => false, 'next_run_at' => Carbon::now()->subHour()]); // inactive
        $this->assertSame(0, app(ScheduledReportDispatcher::class)->dispatchDue());
        $this->assertSame(0, DB::table('report_deliveries')->count());
    }

    public function test_daily_schedule_advances_by_a_day(): void
    {
        $s = $this->schedule(['frequency' => 'daily']);
        $before = $s->next_run_at;
        app(ScheduledReportDispatcher::class)->dispatchDue();
        $this->assertTrue($s->refresh()->next_run_at->greaterThan($before));
    }

    public function test_command_runs(): void
    {
        $this->schedule();
        $this->artisan('reports:dispatch-scheduled')->assertSuccessful();
    }
}
