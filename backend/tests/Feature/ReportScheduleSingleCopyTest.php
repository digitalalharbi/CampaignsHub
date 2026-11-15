<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Services\ScheduledReportDispatcher;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONS-SCHEDULE-ONE — one report per schedule. Copies are switched off (flag + reason + time), never deleted; the
 * command that does it is idempotent; and a new copy cannot be created.
 */
final class ReportScheduleSingleCopyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    /** A schedule saved BEFORE the key existed: inserted raw, so it carries none (as in production). */
    private function legacy(array $over = []): string
    {
        $id = (string) Str::uuid();
        DB::table('report_schedules')->insert(array_merge([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Weekly Exec', 'type' => 'executive', 'frequency' => 'weekly', 'day' => 'sunday',
            'time' => '08:00', 'timezone' => 'Asia/Riyadh', 'audience' => 'client', 'language' => 'ar',
            'formats' => json_encode(['pdf']), 'recipients' => json_encode([['email' => 'client@ext.test']]),
            'active' => true, 'next_run_at' => Carbon::now()->subHour(), 'is_demo' => false,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ], $over));

        return $id;
    }

    private function owner(): User
    {
        $this->seed(PermissionSeeder::class);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function api(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/reports/schedules{$suffix}";
    }

    public function test_duplicates_leave_one_active_the_rest_disabled_nothing_deleted_and_the_command_is_idempotent(): void
    {
        $kept = $this->legacy(['created_at' => Carbon::now()->subDays(3)]);
        $copyA = $this->legacy(['created_at' => Carbon::now()->subDays(2)]);
        $copyB = $this->legacy(['created_at' => Carbon::now()->subDay()]);
        $other = $this->legacy(['name' => 'Monthly', 'frequency' => 'monthly', 'day' => '1']); // a different schedule
        $otherPerson = $this->legacy(['recipients' => json_encode([['email' => 'someone-else@ext.test']])]); // not a copy

        // Dry run: counts, no names, and nothing written.
        Artisan::call('reports:dedupe-schedules', ['--dry-run' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString('[dry-run]', $out);
        $this->assertStringContainsString('copies to switch off: 2', $out);
        $this->assertStringNotContainsString('Weekly Exec', $out);
        $this->assertStringNotContainsString('@ext.test', $out);
        $this->assertSame(5, DB::table('report_schedules')->where('active', true)->count());
        $this->assertSame(0, DB::table('report_schedules')->whereNotNull('schedule_key')->count());

        Artisan::call('reports:dedupe-schedules');
        $this->assertStringContainsString('copies switched off: 2', Artisan::output());

        $this->assertSame(5, DB::table('report_schedules')->count(), 'nothing is deleted');
        $this->assertTrue((bool) DB::table('report_schedules')->where('id', $kept)->value('active'));
        $this->assertTrue((bool) DB::table('report_schedules')->where('id', $other)->value('active'));
        $this->assertTrue((bool) DB::table('report_schedules')->where('id', $otherPerson)->value('active'), 'a different recipient is not a copy');
        foreach ([$copyA, $copyB] as $copy) {
            $row = DB::table('report_schedules')->where('id', $copy)->first();
            $this->assertFalse((bool) $row->active);
            $this->assertNull($row->next_run_at);
            $this->assertSame('duplicate_schedule', $row->disabled_reason);
            $this->assertNotNull($row->disabled_at);
            $this->assertSame($kept, $row->duplicate_of);
            $this->assertSame('Weekly Exec', $row->name, 'its settings are kept');
        }
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'report_schedule.duplicate_disabled')->count());

        // Idempotent: the second run changes nothing.
        $before = DB::table('report_schedules')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        Artisan::call('reports:dedupe-schedules');
        $this->assertStringContainsString('copies switched off: 0', Artisan::output());
        $this->assertSame($before, DB::table('report_schedules')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'report_schedule.duplicate_disabled')->count());

        // The list says which rows are unscheduled copies.
        $res = $this->actingAs($this->owner(), 'sanctum')->getJson($this->api())->assertOk();
        $copies = collect($res->json('data'))->where('disabled_reason', 'duplicate_schedule');
        $this->assertCount(2, $copies);
        $this->assertSame($kept, $copies->first()['duplicate_of']);
    }

    public function test_creation_cannot_make_a_duplicate(): void
    {
        $owner = $this->owner();
        $body = [
            'name' => 'Weekly client report', 'type' => 'executive', 'frequency' => 'weekly', 'day' => 'sunday', 'time' => '08:00',
            'timezone' => 'Asia/Riyadh', 'audience' => 'client', 'language' => 'ar', 'formats' => ['pdf'],
            'recipients' => [['email' => 'client@ext.test']],
        ];

        $first = $this->actingAs($owner, 'sanctum')->postJson($this->api(), $body)->assertCreated();
        // Same report, same moment, same people — spelled differently.
        $this->actingAs($owner, 'sanctum')->postJson($this->api(), ['name' => ' WEEKLY client report ', 'recipients' => [['email' => 'CLIENT@ext.test']]] + $body)
            ->assertStatus(409);
        $this->assertSame(1, ReportSchedule::withoutGlobalScopes()->count());

        // An unkeyed (pre-existing) active twin counts too.
        $this->legacy(['name' => 'Legacy one']);
        $this->actingAs($owner, 'sanctum')->postJson($this->api(), ['name' => 'Legacy one'] + $body)->assertStatus(409);

        // A different recipient is a different delivery, and is allowed.
        $this->actingAs($owner, 'sanctum')->postJson($this->api(), ['recipients' => [['email' => 'cfo@ext.test']]] + $body)->assertCreated();

        // A disabled copy cannot be resumed beside its twin; pause the twin and it can.
        $copy = $this->legacy(['name' => 'Weekly client report', 'active' => false, 'disabled_reason' => 'duplicate_schedule', 'disabled_at' => now(), 'duplicate_of' => $first->json('data.id'), 'next_run_at' => null]);
        $this->actingAs($owner, 'sanctum')->postJson($this->api("/{$copy}/toggle"))->assertStatus(409);
        $this->actingAs($owner, 'sanctum')->postJson($this->api('/'.$first->json('data.id').'/toggle'))->assertOk()->assertJsonPath('data.active', false);
        $this->actingAs($owner, 'sanctum')->postJson($this->api("/{$copy}/toggle"))->assertOk()
            ->assertJsonPath('data.active', true)->assertJsonPath('data.disabled_reason', null);

        // The index refuses a second active keyed copy even around the controller.
        $this->expectException(QueryException::class);
        $twin = ReportSchedule::withoutGlobalScopes()->findOrFail($copy)->replicate(['schedule_key']);
        $twin->forceFill(['id' => (string) Str::uuid(), 'active' => true])->save();
    }

    public function test_a_due_duplicate_builds_no_second_report_before_the_command_runs(): void
    {
        $this->legacy(['created_at' => Carbon::now()->subDay()]);
        $this->legacy();
        $this->legacy();

        app(ScheduledReportDispatcher::class)->dispatchDue();

        $this->assertSame(1, Report::withoutGlobalScopes()->count(), 'one report per schedule');
        $this->assertSame(0, ReportSchedule::withoutGlobalScopes()->where('next_run_at', '<=', Carbon::now())->count(), 'every copy\'s run was taken');
    }
}
