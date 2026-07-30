<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Services\ScheduledReportDispatcher;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
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

    /** An owner with every permission — the schedule API is permission-gated (reports.view/export). */
    private function owner(): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o-'.uniqid().'@a.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function api(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/reports/schedules{$suffix}";
    }

    /**
     * REPORT-SCHEDULING: creating a schedule over HTTP must produce a schedule the CRON will actually
     * fire — the API and the dispatcher must agree on when that is, or the UI would be lying.
     */
    public function test_creating_a_schedule_computes_the_same_next_run_the_dispatcher_would(): void
    {
        $this->seed(PermissionSeeder::class);
        $owner = $this->owner();

        $res = $this->actingAs($owner, 'sanctum')->postJson($this->api(), [
            'name' => 'Weekly client report', 'type' => 'executive', 'frequency' => 'weekly',
            'day' => 'sunday', 'time' => '08:00', 'timezone' => 'Asia/Riyadh',
            'audience' => 'client', 'language' => 'ar', 'formats' => ['pdf'],
            'recipients' => [['email' => 'client@ext.test', 'name' => 'Client']],
        ])->assertCreated();

        $id = $res->json('data.id');
        $this->assertNotNull($res->json('data.next_run_at'), 'a new schedule must know when it fires');
        $this->assertTrue($res->json('data.active'));
        // Nothing has been delivered yet — creating a schedule is not a send.
        $this->assertSame([], $res->json('data.deliveries'));

        $model = ReportSchedule::withoutGlobalScopes()->find($id);
        $expected = app(ScheduledReportDispatcher::class)->nextRun($model, Carbon::now());
        $this->assertSame($expected->toIso8601String(), $model->next_run_at->toIso8601String());
    }

    public function test_pausing_stops_the_schedule_and_resuming_recomputes_from_now(): void
    {
        $this->seed(PermissionSeeder::class);
        $owner = $this->owner();
        $s = $this->schedule(['next_run_at' => Carbon::now()->subMonth()]);

        $this->actingAs($owner, 'sanctum')->postJson($this->api("/{$s->id}/toggle"))
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.next_run_at', null);

        // A paused schedule is invisible to the dispatcher — no backlog fires.
        $this->assertSame(0, app(ScheduledReportDispatcher::class)->dispatchDue(Carbon::now()));

        $resumed = $this->actingAs($owner, 'sanctum')->postJson($this->api("/{$s->id}/toggle"))->assertOk();
        $this->assertTrue($resumed->json('data.active'));
        $this->assertTrue(
            Carbon::parse($resumed->json('data.next_run_at'))->isFuture(),
            'resuming must schedule forward, never replay a month of missed runs',
        );
    }

    public function test_a_custom_frequency_without_a_cron_expression_is_refused(): void
    {
        $this->seed(PermissionSeeder::class);

        // Falling back to "daily" would schedule something other than what was asked for.
        $this->actingAs($this->owner(), 'sanctum')->postJson($this->api(), [
            'name' => 'Custom', 'type' => 'executive', 'frequency' => 'custom', 'time' => '08:00',
        ])->assertStatus(422);
    }

    public function test_schedule_writes_require_permission_and_reads_are_project_scoped(): void
    {
        $this->seed(PermissionSeeder::class);
        $viewer = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'V', 'email' => 'v-'.uniqid().'@a.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($viewer, $this->tenant);

        $this->actingAs($viewer, 'sanctum')->getJson($this->api())->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson($this->api(), ['name' => 'X', 'type' => 'executive', 'frequency' => 'daily'])->assertForbidden();

        $this->schedule();
        $this->assertCount(1, $this->actingAs($this->owner(), 'sanctum')->getJson($this->api())->assertOk()->json('data'));
    }

    /** Running now goes through the SAME dispatcher, so a manual run is a real run. */
    public function test_run_now_generates_a_report_through_the_dispatcher(): void
    {
        $this->seed(PermissionSeeder::class);
        $s = $this->schedule();

        $res = $this->actingAs($this->owner(), 'sanctum')->postJson($this->api("/{$s->id}/run"))->assertOk();

        $this->assertNotNull($res->json('data.report_id'));
        Queue::assertPushed(GenerateReportJob::class);
        $this->assertDatabaseHas('report_deliveries', ['schedule_id' => $s->id, 'recipient' => 'client@ext.test']);
        // Still never "sent" — no mail provider has acknowledged anything.
        $this->assertDatabaseMissing('report_deliveries', ['schedule_id' => $s->id, 'status' => 'sent']);
    }

    /**
     * Regression: Carbon::next() rewinds to midnight, so a weekly schedule was firing at 00:00 instead
     * of the hour it was configured for. The next run must land on the configured local time.
     */
    public function test_weekly_schedule_fires_at_its_configured_local_time_not_midnight(): void
    {
        // Wednesday — the next Sunday is four days out.
        Carbon::setTestNow(Carbon::parse('2026-07-29 10:00:00', 'UTC'));

        $s = $this->schedule(['frequency' => 'weekly', 'day' => 'sunday', 'time' => '08:00', 'timezone' => 'Asia/Riyadh']);
        $next = app(ScheduledReportDispatcher::class)->nextRun($s, Carbon::now());

        $local = $next->copy()->setTimezone('Asia/Riyadh');
        $this->assertSame('08:00', $local->format('H:i'), 'the schedule must fire at its configured hour');
        $this->assertSame('Sunday', $local->englishDayOfWeek);
        $this->assertSame('2026-08-02', $local->toDateString());

        Carbon::setTestNow();
    }
}
