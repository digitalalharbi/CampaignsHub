<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ScheduledReportDispatcher;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONS-DELIVERY-SCHEDULED — /agency/delivery said «لا توجد حزمة بعد» for every client while one client's scheduled
 * reports were going out. The page read only weekly packs; it now also reads the client's ACTUAL report schedules, and
 * the scheduled report says «ready» once.
 */
final class DeliveryScheduledReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ClientWorkspace $client;

    private ClientWorkspace $quiet;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);

        $this->client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Client A', 'slug' => 'a-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->quiet = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Client B', 'slug' => 'b-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'Store', 'status' => 'active']);
        Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->quiet->id, 'name' => 'Other', 'status' => 'active']);
    }

    private function schedule(Tenant $tenant, Project $project): ReportSchedule
    {
        $s = new ReportSchedule;
        $s->forceFill([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'name' => 'Weekly', 'type' => 'executive', 'frequency' => 'weekly', 'day' => 'sunday', 'time' => '08:00',
            'recipients' => [['email' => 'client@ext.test']], 'formats' => ['pdf'], 'active' => true,
            'next_run_at' => Carbon::now()->subHour(), 'created_by' => $this->owner->id,
        ])->save();

        return $s;
    }

    public function test_a_scheduled_report_says_ready_once_and_the_delivery_page_lists_it(): void
    {
        $schedule = $this->schedule($this->tenant, $this->project);
        $this->assertSame(1, app(ScheduledReportDispatcher::class)->dispatchDue());
        $report = Report::withoutGlobalScopes()->where('project_id', $this->project->id)->sole();

        // The queue retries, a note rebuilds it, a pack refresh rebuilds it — 24 times.
        for ($i = 0; $i < 24; $i++) {
            $this->travel(2)->hours();
            (new GenerateReportJob((string) $report->id))->handle(app(ReportGenerator::class));
        }
        $this->assertSame(1, AppNotification::query()->where('type', 'report_ready')->where('entity_id', $report->id)->count());

        $rows = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/delivery/overview')->assertOk()->json('data'))->keyBy('client_id');

        $a = $rows[(string) $this->client->id];
        $this->assertNull($a['pack'], 'no weekly pack was prepared');
        $this->assertSame(1, $a['scheduled']['active']);
        $this->assertSame((string) $report->id, $a['scheduled']['last_report']['id']);
        $this->assertSame('completed', $a['scheduled']['last_report']['status']);
        $this->assertNotNull($a['scheduled']['last_run_at']);
        $this->assertNotNull($a['scheduled']['next_run_at']);
        $this->assertSame(['awaiting_provider_credentials' => 1], $a['scheduled']['deliveries']);
        $this->assertSame('none', $a['next_action'], 'its reports go out on schedule; nothing is asked of the operator');

        // A client with no schedule still reads as before.
        $b = $rows[(string) $this->quiet->id];
        $this->assertSame(0, $b['scheduled']['active']);
        $this->assertNull($b['scheduled']['last_report']);
        $this->assertSame('prepare_pack', $b['next_action']);

        // A disabled duplicate copy is not counted as a second schedule.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        ReportSchedule::withoutGlobalScopes()->whereKey($schedule->id)->update(['active' => false, 'disabled_reason' => 'duplicate_schedule', 'disabled_at' => now()]);
        $this->assertSame(0, $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/delivery/overview')->json('data.0.scheduled.active'));
    }

    public function test_another_tenants_schedules_never_reach_this_page(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $theirClient = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'Theirs', 'slug' => 't-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $theirProject = Project::create(['tenant_id' => $other->id, 'client_workspace_id' => $theirClient->id, 'name' => 'T', 'status' => 'active']);
        $this->schedule($other, $theirProject);
        app(ScheduledReportDispatcher::class)->dispatchDue();
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $rows = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/delivery/overview')->assertOk()->json('data'));

        $this->assertNotContains((string) $theirClient->id, $rows->pluck('client_id')->all());
        $this->assertSame([0, 0], $rows->pluck('scheduled.active')->all());
    }
}
