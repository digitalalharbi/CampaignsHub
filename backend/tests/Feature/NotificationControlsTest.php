<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\NotificationChoices;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * §17.4.1, §17.4.3, §17.4.5 — what a person can control, and what they cannot widen.
 */
final class NotificationControlsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private Project $alpha;

    private Project $beta;

    private Project $foreign;

    private User $manager;

    private User $scoped;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-controls', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'B', 'slug' => 'b-controls', 'status' => 'active']);

        app(TenantContext::class)->setTenantId($this->otherTenant->id);
        $wsB = ClientWorkspace::create(['name' => 'Foreign', 'slug' => 'foreign-controls', 'mode' => 'managed']);
        $this->foreign = Project::create(['client_workspace_id' => $wsB->id, 'name' => 'Foreign project', 'status' => 'active']);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $one = ClientWorkspace::create(['name' => 'One', 'slug' => 'one-controls', 'mode' => 'managed']);
        $two = ClientWorkspace::create(['name' => 'Two', 'slug' => 'two-controls', 'mode' => 'managed']);
        $this->alpha = Project::create(['client_workspace_id' => $one->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->beta = Project::create(['client_workspace_id' => $two->id, 'name' => 'Beta', 'status' => 'active']);

        $managerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Manager', 'slug' => 'manager-controls']);
        $managerRole->givePermissionTo('clients.view_all', 'settings.manage');
        $memberRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Member', 'slug' => 'member-controls']);

        $this->manager = $this->member('manager@controls.test', $managerRole);
        $this->scoped = $this->member('scoped@controls.test', $memberRole, (string) $this->alpha->id);

        app(TenantContext::class)->forget();
    }

    private function member(string $email, Role $role, ?string $project = null): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $membership = Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'portal' => 'agency', 'status' => 'active']);
        if ($project !== null) {
            MembershipScope::create(['membership_id' => $membership->id, 'scope_type' => MembershipScope::TYPE_PROJECT, 'scope_id' => $project]);
        }
        $user->assignRole($role);

        return $user->fresh();
    }

    private function save(array $body, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager, 'sanctum')
            ->withHeader('X-Tenant-Id', (string) $this->tenant->id)
            ->putJson('/api/v1/settings/notifications', $body);
    }

    private function read(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager, 'sanctum')
            ->withHeader('X-Tenant-Id', (string) $this->tenant->id)
            ->getJson('/api/v1/settings/notifications');
    }

    public function test_a_finding_can_be_held_for_the_monthly_summary_and_an_event_cannot(): void
    {
        $this->assertContains('monthly', MessageCatalogue::rhythmsFor('budget_pace'));

        $this->save(['types' => ['budget_pace' => ['rhythm' => 'monthly']]])->assertOk();
        $this->assertSame('monthly', $this->read()->json('data.types.budget_pace.rhythm'));

        $this->save(['types' => ['subscription' => ['rhythm' => 'monthly']]])->assertStatus(422);
    }

    public function test_importance_is_stored_per_type_and_the_sender_honours_it(): void
    {
        $this->save(['types' => ['budget_pace' => ['min_severity' => 'critical']]])->assertOk();
        $this->assertSame('critical', $this->read()->json('data.types.budget_pace.min_severity'));

        $choices = app(NotificationChoices::class);
        $choices->forget();
        $tenant = (string) $this->tenant->id;
        $this->assertTrue($choices->meetsImportance($this->manager->id, $tenant, 'budget_pace', 'critical'));
        $this->assertFalse($choices->meetsImportance($this->manager->id, $tenant, 'budget_pace', 'warning'));
        // A type nobody narrowed lets everything through, and a mandatory one cannot be narrowed.
        $this->assertTrue($choices->meetsImportance($this->manager->id, $tenant, 'rising_cost', 'warning'));

        $this->save(['types' => ['budget_pace' => ['min_severity' => 'loud']]])->assertStatus(422);
        $this->save(['types' => ['password_reset' => ['min_severity' => 'critical']]])->assertStatus(422);
    }

    public function test_the_screen_is_told_which_channels_exist(): void
    {
        $channels = collect($this->read()->json('data.available_channels'))->keyBy('key');

        $this->assertTrue($channels['in_app']['available']);
        // No SMS adapter is wired, so it is listed and not offered. A switch for it would be a lie.
        $this->assertFalse($channels['sms']['available']);
        $this->assertArrayHasKey('email', $channels->all());
    }

    public function test_a_security_message_cannot_be_switched_off(): void
    {
        foreach (['password_reset', 'sign_in_code', 'email_verification', 'security_alert', 'invitation', 'member_setup', 'role_changed'] as $type) {
            $this->assertTrue(MessageCatalogue::isMandatory($type), $type);
            $this->save(['types' => [$type => ['email' => false]]])->assertStatus(422);
            $this->assertTrue(app(NotificationChoices::class)->wants($this->manager->id, (string) $this->tenant->id, $type, 'email'));
        }
    }

    /** §17.4.3 — no option a person can set widens what they reach. */
    public function test_a_digest_scope_cannot_be_widened_by_a_preference(): void
    {
        $scope = app(DigestScope::class);
        $tenant = (string) $this->tenant->id;

        $this->save(['project_ids' => [(string) $this->beta->id, (string) $this->foreign->id]], $this->scoped)->assertOk();
        app(TenantContext::class)->setTenantId($tenant);
        // The scoped member named a sibling's project and another tenant's: neither appears, and their own
        // ceiling is not silently substituted either.
        $this->assertSame([], $scope->projectIdsFor($this->scoped, $tenant));

        $this->save(['project_ids' => [(string) $this->alpha->id, (string) $this->foreign->id]], $this->manager)->assertOk();
        app(TenantContext::class)->setTenantId($tenant);
        $this->assertSame([(string) $this->alpha->id], $scope->projectIdsFor($this->manager, $tenant), 'another tenant\'s project entered a digest');

        // The other tenant's own digest never reaches this tenant's people at all.
        $this->assertSame([], $scope->projectIdsFor($this->manager, (string) $this->otherTenant->id));

        // Choosing nothing means everything authorised, never more.
        $this->save(['project_ids' => null], $this->scoped)->assertOk();
        app(TenantContext::class)->setTenantId($tenant);
        $this->assertSame([(string) $this->alpha->id], $scope->projectIdsFor($this->scoped, $tenant));
    }

    /** A manager reviews the templates. Sending one is the platform admin's alone: `MailTestSendTest`. */
    public function test_a_preview_needs_the_manager_permission_and_sends_nothing(): void
    {
        Mail::fake();
        $tenant = (string) $this->tenant->id;

        $this->actingAs($this->scoped, 'sanctum')->withHeader('X-Tenant-Id', $tenant)->getJson('/api/v1/settings/notifications/previews')->assertStatus(403);
        $this->actingAs($this->scoped, 'sanctum')->withHeader('X-Tenant-Id', $tenant)->getJson('/api/v1/settings/notifications/previews/alerts-one')->assertStatus(403);

        $keys = $this->actingAs($this->manager, 'sanctum')->withHeader('X-Tenant-Id', $tenant)->getJson('/api/v1/settings/notifications/previews')->assertOk()->json('data.keys');
        $this->assertSame(MailGallery::keys(), $keys);
        $html = $this->actingAs($this->manager, 'sanctum')->withHeader('X-Tenant-Id', $tenant)->getJson('/api/v1/settings/notifications/previews/alerts-one?locale=en')->assertOk()->json('data.html');
        $this->assertStringContainsString('ch-wrap', $html);

        Mail::assertNothingSent();
    }

    /** A team's log shows this tenant's rows and says which stage each reached. */
    public function test_the_team_log_reads_in_stages_and_stays_inside_the_tenant(): void
    {
        foreach ([[$this->tenant->id, 'mine@example.test', 'sent'], [$this->otherTenant->id, 'theirs@example.test', 'failed']] as [$tenant, $to, $status]) {
            DB::table('mail_deliveries')->insert([
                'id' => (string) Uuid::uuid4(), 'tenant_id' => $tenant, 'kind' => 'report_ready', 'recipient' => $to, 'locale' => 'ar',
                'template' => 'mail.operational', 'status' => $status, 'transport' => 'live', 'attempts' => 1, 'failure_code' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $rows = $this->actingAs($this->manager, 'sanctum')->withHeader('X-Tenant-Id', (string) $this->tenant->id)
            ->getJson('/api/v1/settings/notifications/deliveries')->assertOk()->json('data');

        $this->assertSame(['mine@example.test'], array_column($rows, 'recipient'));
        $this->assertSame('accepted', $rows[0]['state']);
    }
}
