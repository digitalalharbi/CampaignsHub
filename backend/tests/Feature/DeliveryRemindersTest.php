<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Delivery\Services\DeliveryPackService;
use App\Domains\Delivery\Services\DeliverySettings;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DELIVERY-REMINDERS-001 — a delivery names its reviewer, the review task is assigned to them, and they are nudged — once a day per
 * reason — when a draft waits, a report fails or an approved pack is not sent. Nothing is ever sent to a client, and with no
 * reviewer chosen nothing is guessed.
 */
final class DeliveryRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $reviewer;

    private User $viewer;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $owner = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $view = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ReadOnly', 'slug' => 'readonly']);
        $view->givePermissionTo('clients.view', 'clients.view_all', 'reports.view');
        $this->owner = $this->member('Owner', 'o@a.test', $owner);
        $this->reviewer = $this->member('Reviewer', 'rev@a.test', $owner);
        $this->viewer = $this->member('Viewer', 'v@a.test', $view);
        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Store', 'status' => 'active']);
    }

    private function member(string $name, string $email, Role $role): User
    {
        $u = User::create(['name' => $name, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($u, $this->tenant, Portal::Agency);
        $u->assignRole($role);

        return $u;
    }

    private function settings(array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->putJson('/api/v1/delivery/settings', ['client_workspace_id' => $this->workspace->id] + $body);
    }

    private function pack(): DeliveryPack
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return app(DeliveryPackService::class)->prepare($this->project, Carbon::parse('2026-10-19'), $this->owner);
    }

    private function deliveries(string $kind): int
    {
        return DB::table('mail_deliveries')->where('kind', $kind)->count();
    }

    public function test_the_reviewer_and_reminder_time_are_saved_validated_and_offered_from_people_who_may_approve(): void
    {
        $r = $this->settings(['reviewer_id' => $this->reviewer->id, 'reminder_hours' => 12])->assertOk();
        $r->assertJsonPath('data.reviewer_id', $this->reviewer->id)->assertJsonPath('data.reminder_hours', 12);
        $ids = array_column($r->json('data.reviewers'), 'id');
        $this->assertContains($this->reviewer->id, $ids);
        $this->assertNotContains($this->viewer->id, $ids, 'a read-only member cannot be a reviewer');

        $this->settings(['reviewer_id' => $this->viewer->id])->assertStatus(422);
        $this->settings(['reviewer_id' => 999999])->assertStatus(422);
        $this->settings(['reminder_hours' => 0])->assertStatus(422);
        $this->settings(['reminder_hours' => 500])->assertStatus(422);
        $this->settings(['reviewer_id' => null])->assertOk()->assertJsonPath('data.reviewer_id', null);
    }

    public function test_the_review_task_is_assigned_to_the_chosen_reviewer(): void
    {
        $this->settings(['reviewer_id' => $this->reviewer->id])->assertOk();
        $pack = $this->pack();
        $this->assertSame($this->reviewer->id, (int) Task::withoutGlobalScopes()->where('meta->delivery_pack_id', (string) $pack->id)->value('assignee_id'));
    }

    public function test_a_waiting_draft_reminds_its_reviewer_once_a_day_and_not_before_the_wait_is_over(): void
    {
        $this->settings(['reviewer_id' => $this->reviewer->id, 'reminder_hours' => 24])->assertOk();
        $pack = $this->pack();
        $pack->forceFill(['created_at' => Carbon::parse('2026-10-20 06:00')])->save();

        $this->artisan('delivery:remind', ['--now' => '2026-10-20 18:00'])->expectsOutputToContain('0 reminder(s) due')->assertSuccessful();
        $this->assertSame(0, $this->deliveries('delivery_review_due'));

        $this->artisan('delivery:remind', ['--now' => '2026-10-21 09:00'])->expectsOutputToContain('1 reminder(s) due')->assertSuccessful();
        $this->artisan('delivery:remind', ['--now' => '2026-10-21 09:00'])->assertSuccessful();
        $this->assertSame(1, $this->deliveries('delivery_review_due'), 'the same reason on the same day is one message');
        $row = DB::table('mail_deliveries')->where('kind', 'delivery_review_due')->first();
        $this->assertSame('rev@a.test', $row->recipient);
        $this->assertNotSame('sent', $row->status, 'with no SMTP the ledger says so — it is not recorded as delivered');
    }

    public function test_a_failed_report_and_an_approved_unsent_pack_each_remind_for_their_own_reason(): void
    {
        $this->settings(['reviewer_id' => $this->reviewer->id])->assertOk();
        $pack = $this->pack();
        Report::withoutGlobalScopes()->whereKey($pack->report_id)->update(['status' => 'failed']);
        $this->artisan('delivery:remind', ['--now' => '2026-10-19 09:00'])->assertSuccessful();
        $this->assertSame(1, $this->deliveries('delivery_report_failed'));

        Report::withoutGlobalScopes()->whereKey($pack->report_id)->update(['status' => 'completed', 'data' => json_encode(['totals' => ['conversions' => 1], 'currency' => 'USD'])]);
        app(DeliveryPackService::class)->approve($pack->fresh(), $this->owner);
        $this->artisan('delivery:remind', ['--now' => '2026-10-19 20:00'])->assertSuccessful();
        $this->assertSame(0, $this->deliveries('delivery_send_due'), 'two days after the period ended, not before');
        $this->artisan('delivery:remind', ['--now' => '2026-10-25 09:00'])->assertSuccessful();
        $this->assertSame(1, $this->deliveries('delivery_send_due'));
    }

    public function test_nothing_is_sent_without_a_reviewer_for_a_sent_pack_or_an_archived_client(): void
    {
        $pack = $this->pack();
        $this->artisan('delivery:remind', ['--now' => '2026-11-30 09:00'])->expectsOutputToContain('0 reminder(s) due')->assertSuccessful();

        $this->settings(['reviewer_id' => $this->reviewer->id])->assertOk();
        $pack->forceFill(['status' => 'sent', 'sent_at' => Carbon::now()])->save();
        $this->artisan('delivery:remind', ['--now' => '2026-11-30 09:00'])->expectsOutputToContain('0 reminder(s) due')->assertSuccessful();

        $pack->forceFill(['status' => 'draft', 'sent_at' => null])->save();
        $this->workspace->forceFill(['archived_at' => Carbon::now()])->save();
        $this->artisan('delivery:remind', ['--now' => '2026-11-30 09:00'])->expectsOutputToContain('0 reminder(s) due')->assertSuccessful();
        $this->assertSame(0, DB::table('mail_deliveries')->where('kind', 'like', 'delivery_%')->count());
    }

    public function test_a_reviewer_outside_this_tenant_is_never_accepted_and_the_defaults_hold(): void
    {
        $defaults = DeliverySettings::sanitise([]);
        $this->assertNull($defaults['reviewer_id']);
        $this->assertSame(24, $defaults['reminder_hours']);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create(['name' => 'S', 'email' => 's@x.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $other, Portal::Agency);
        $stranger->assignRole($role);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->settings(['reviewer_id' => $stranger->id])->assertStatus(422);
    }
}
