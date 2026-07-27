<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
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

/**
 * The milestone acceptance flow, end to end, in the mandated order:
 *   Suspended User Blocked → Existing Session Revoked → Workspace Invitation Accepted → Correct Modules Visible
 *   → Forbidden Module API Denied → Scheduled Report Created → Report Generated → Delivery Logged Honestly →
 *   Alert Triggered → Notification Received → Refresh & Persistence Verified.
 * One narrative test proving the links compose (each link also has its own focused test suite).
 */
final class MilestoneAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // report generation is queued — never faked as "done"
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active',
            'account_type' => 'agency', 'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst'])
            ->givePermissionTo('campaigns.view', 'reports.view');

        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'o@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->owner->assignRole($ownerRole);
    }

    public function test_full_milestone_acceptance_flow(): void
    {
        // 1) SUSPENDED USER BLOCKED — a disabled user cannot log in, with a non-revealing message.
        $suspended = User::create(['tenant_id' => $this->tenant->id, 'name' => 'S', 'email' => 's@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $suspended->forceFill(['disabled_at' => now()])->save();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 's@a.test', 'password' => 'secret1234'])
            ->assertForbidden();

        // 2) EXISTING SESSION REVOKED — a previously-valid API session for that user is denied on every route.
        $this->actingAs($suspended, 'sanctum')->getJson('/api/v1/auth/me')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.blocked_suspended', 'entity_id' => (string) $suspended->id]);

        // 3) WORKSPACE INVITATION ACCEPTED — invite to the EXISTING workspace, accept, join with the role.
        $invite = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/app/team/invitations', [
            'email' => 'member@a.test', 'role_slug' => 'analyst',
        ])->assertCreated()->assertJsonPath('data.delivery_status', 'awaiting_provider_credentials');
        $token = explode('token=', (string) $invite->json('data.dev_link'))[1];

        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'New Member', 'password' => 'secret1234'])
            ->assertCreated()->assertJsonPath('data.user.role_slug', 'analyst');
        $member = User::where('email', 'member@a.test')->firstOrFail();
        $this->assertSame($this->tenant->id, $member->tenant_id); // joined existing tenant, no new workspace
        $this->assertSame(1, Tenant::where('slug', 'agency')->count());

        // The accept endpoint logs the new member into the web/session guard; clear that resolved state and
        // any carried session so the following requests authenticate cleanly as the owner via sanctum.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // 4) CORRECT MODULES VISIBLE — the personal (agency) workspace exposes the paid-media modules.
        $me = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame('personal', $me->json('data.user.account.workspace_kind'));
        $this->assertContains('paid_media', $me->json('data.user.account.enabled_modules'));
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/app/clients')->assertOk(); // module allowed

        // 5) FORBIDDEN MODULE API DENIED — a company (brand) workspace is fail-closed on client-management APIs.
        $companyDenied = $this->companyMemberDeniedClients();
        $this->assertTrue($companyDenied, 'A company workspace member must be denied /app/clients.');

        // 6) SCHEDULED REPORT CREATED — a due schedule for a real client/project.
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $schedule = new ReportSchedule;
        $schedule->forceFill([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'name' => 'Weekly', 'type' => 'executive', 'frequency' => 'weekly', 'day' => 'sunday', 'time' => '08:00',
            'timezone' => 'Asia/Riyadh', 'audience' => 'client', 'language' => 'ar', 'formats' => ['pdf'],
            'recipients' => [['email' => 'client@ext.test', 'name' => 'Client']],
            'active' => true, 'next_run_at' => Carbon::now()->subHour(),
        ])->save();

        // 7) REPORT GENERATED — the dispatcher resolves the schedule and queues real generation (not faked done).
        $dispatched = app(ScheduledReportDispatcher::class)->dispatchDue();
        $this->assertSame(1, $dispatched);
        $this->assertSame(1, Report::where('project_id', $project->id)->count());
        Queue::assertPushed(GenerateReportJob::class);

        // 8) DELIVERY LOGGED HONESTLY — awaiting_provider_credentials, never "sent".
        $this->assertDatabaseHas('report_deliveries', ['schedule_id' => $schedule->id, 'recipient' => 'client@ext.test', 'status' => 'awaiting_provider_credentials']);
        $this->assertDatabaseMissing('report_deliveries', ['status' => 'sent']);

        // 9) ALERT TRIGGERED — a failed sync raises exactly one alert (cooldown/dedup prevent storms).
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'connection_id' => (string) Str::uuid(), 'provider' => 'meta', 'status' => 'failed',
            'window_start' => Carbon::now()->subDay()->toDateString(), 'window_end' => Carbon::now()->toDateString(),
            'error' => 'token expired', 'started_at' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $rule = AlertRule::create(['tenant_id' => $this->tenant->id, 'type' => 'sync_failure', 'name' => 'Sync', 'cooldown_minutes' => 720, 'severity' => 'critical', 'active' => true]);
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $this->assertSame(1, AlertEvent::where('rule_id', $rule->id)->where('status', 'open')->count());

        // 10) NOTIFICATION RECEIVED — the alert surfaced in the notification center, delivered honestly.
        $notification = AppNotification::where('type', 'sync_failed')->firstOrFail();
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $notification->id, 'channel' => 'email', 'status' => 'awaiting_credentials']);
        $inbox = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/notifications')->assertOk();
        $this->assertContains('sync_failed', array_column((array) $inbox->json('data'), 'type'));

        // 11) REFRESH & PERSISTENCE VERIFIED — a re-fetch (new request cycle) returns the same durable state.
        $again = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/notifications')->assertOk();
        $this->assertContains('sync_failed', array_column((array) $again->json('data'), 'type'));
        $this->assertSame(1, AlertEvent::where('rule_id', $rule->id)->count());     // no duplicate on re-read
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));       // cooldown holds across "refresh"
    }

    /** A brand (company) workspace member must be denied client-management APIs (entitlement fail-closed). */
    private function companyMemberDeniedClients(): bool
    {
        $company = Tenant::create(['name' => 'Brand', 'slug' => 'brand', 'status' => 'active',
            'account_type' => 'brand', 'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        $prev = app(TenantContext::class)->tenantId();
        app(TenantContext::class)->setTenantId($company->id);

        $role = Role::create(['tenant_id' => $company->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['tenant_id' => $company->id, 'name' => 'B', 'email' => 'b@brand.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $user->assignRole($role);

        $status = $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/clients')->getStatusCode();

        app(TenantContext::class)->setTenantId($prev);

        return $status === 403;
    }
}
