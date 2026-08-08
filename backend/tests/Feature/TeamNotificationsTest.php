<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * MAIL-012 — «Sara says she never gets the budget alerts.»
 *
 * Answering that used to mean a developer with database access: her preference row, her memberships,
 * whether an arrangement names her, whether anything was ever sent to her address, and whether the
 * mailer was configured on the day it would have gone out.
 *
 * The tests below are mostly about the two states that look identical from outside — «nothing will
 * ever reach this person» and «nothing has happened yet» — because a screen that conflates them
 * sends a manager looking for a bug that is not there, or lets a real misconfiguration sit.
 */
final class TeamNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $alpha;

    private Project $beta;

    private User $owner;

    private User $scoped;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-08 09:00:00', 'UTC'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-team-notif', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $one = ClientWorkspace::create(['name' => 'One', 'slug' => 'one-tn', 'mode' => 'managed']);
        $two = ClientWorkspace::create(['name' => 'Two', 'slug' => 'two-tn', 'mode' => 'managed']);
        $this->alpha = Project::create(['client_workspace_id' => $one->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->beta = Project::create(['client_workspace_id' => $two->id, 'name' => 'Beta', 'status' => 'active']);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-tn']);
        $ownerRole->givePermissionTo('clients.view_all');
        $ownerRole->givePermissionTo('settings.manage');

        $memberRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Member', 'slug' => 'member-tn']);
        $memberRole->givePermissionTo('settings.manage');

        $this->owner = $this->member('owner@tn.test', $ownerRole);
        $this->scoped = $this->member('scoped@tn.test', $memberRole, (string) $this->alpha->id);
        $this->stranger = $this->member('stranger@tn.test', $memberRole, (string) $this->beta->id);

        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $email, Role $role, ?string $projectScope = null): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $membership = Membership::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'portal' => 'agency', 'status' => 'active',
        ]);
        if ($projectScope !== null) {
            MembershipScope::create([
                'membership_id' => $membership->id,
                'scope_type' => MembershipScope::TYPE_PROJECT,
                'scope_id' => $projectScope,
            ]);
        }
        $user->assignRole($role);

        return $user->fresh();
    }

    /** @return array<int, array<string, mixed>> keyed by email, so assertions read as sentences */
    private function board(?User $as = null): array
    {
        $people = $this->actingAs($as ?? $this->owner, 'sanctum')
            ->getJson('/api/v1/settings/notifications/team')->assertOk()->json('data.people');

        return collect($people)->keyBy('email')->all();
    }

    private function prefer(User $user, array $row): void
    {
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $user->id,
            'channels' => json_encode($row['channels'] ?? ['in_app' => true, 'email' => true]),
            'categories' => json_encode($row['categories'] ?? []),
            'digests' => json_encode($row['digests'] ?? ['daily' => false, 'weekly' => false, 'alerts' => false]),
            'frequency' => 'realtime',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_screen_is_closed_to_somebody_without_settings_manage(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer-tn']);
        $viewer = $this->member('viewer@tn.test', $role, (string) $this->alpha->id);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/settings/notifications/team')->assertStatus(403);
    }

    /**
     * **Fail-closed.** A scoped manager sees colleagues on their own projects and nobody else.
     *
     * The listing names people, their clients and their addresses. A settings screen must not be the
     * way somebody scoped to one client learns who else works here and on what.
     */
    public function test_a_scoped_manager_sees_only_people_who_share_a_project_with_them(): void
    {
        $seen = array_keys($this->board($this->scoped));

        $this->assertContains('scoped@tn.test', $seen);
        $this->assertContains('owner@tn.test', $seen, 'the owner reaches Alpha too');
        $this->assertNotContains('stranger@tn.test', $seen, 'a colleague on another client was disclosed');
    }

    /** And the projects named are the overlap, not everything the other person reaches. */
    public function test_the_projects_named_are_the_ones_the_reader_can_already_see(): void
    {
        $row = $this->board($this->scoped)['owner@tn.test'];

        $this->assertSame(['One · Alpha'], $row['projects']);
    }

    /**
     * «Receives nothing» is a state of its own, and it is not «nothing happened yet».
     *
     * Both render as an empty inbox; one is a settings mistake and the other is an ordinary quiet
     * week. Conflating them is how a real misconfiguration sits for a month.
     */
    public function test_somebody_who_will_never_receive_anything_is_named_as_such(): void
    {
        $this->prefer($this->scoped, [
            'channels' => ['in_app' => true, 'email' => false],
            'digests' => ['daily' => false, 'weekly' => false, 'alerts' => false],
        ]);

        $this->assertSame('silent', $this->board()['scoped@tn.test']['state']);
        $this->assertSame([], $this->board()['scoped@tn.test']['categories']);
    }

    public function test_somebody_subscribed_with_an_empty_ledger_is_waiting_not_silenced(): void
    {
        $this->prefer($this->scoped, ['digests' => ['daily' => true, 'weekly' => false, 'alerts' => true]]);

        $row = $this->board()['scoped@tn.test'];

        $this->assertSame('never_sent', $row['state']);
        $this->assertTrue($row['rhythms']['daily']);
        $this->assertTrue($row['rhythms']['alerts']);
    }

    /**
     * The digest ledger counts as «a message was sent», not only the transactional one.
     *
     * Reading `mail_deliveries` alone would report «nothing has ever been sent» to somebody who
     * receives a summary every morning — the two ledgers hold different halves of the answer.
     */
    public function test_a_digest_send_is_the_last_message_even_though_it_is_in_the_other_ledger(): void
    {
        $this->prefer($this->scoped, ['digests' => ['daily' => true, 'weekly' => false, 'alerts' => false]]);

        DB::table('digest_sends')->insert([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->scoped->id,
            'kind' => 'daily',
            'period_key' => '2026-08-07',
            'status' => 'awaiting_credentials',
            'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->board()['scoped@tn.test'];

        $this->assertSame('awaiting_credentials', $row['state']);
        $this->assertSame('daily', $row['last_message']['kind']);
        $this->assertSame('digest', $row['last_message']['source']);
    }

    /** The more recent of the two ledgers wins, whichever it is. */
    public function test_the_most_recent_attempt_is_the_one_reported(): void
    {
        $this->prefer($this->scoped, ['digests' => ['daily' => true, 'weekly' => false, 'alerts' => false]]);

        DB::table('digest_sends')->insert([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->scoped->id, 'kind' => 'daily', 'period_key' => '2026-08-05',
            'status' => 'sent', 'attempts' => 1, 'sent_at' => now()->subDays(3),
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);

        DB::table('mail_deliveries')->insert([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->scoped->id, 'kind' => 'password_reset', 'recipient' => 'scoped@tn.test',
            'locale' => 'ar', 'template' => 'credential', 'status' => 'failed', 'transport' => 'log',
            'attempts' => 1, 'dedup_key' => 'k1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->board()['scoped@tn.test'];

        $this->assertSame('failed', $row['state']);
        $this->assertSame('transactional', $row['last_message']['source']);
    }

    /**
     * The reason every row says «awaiting credentials» is stated once, as a fact about the install.
     *
     * Without it a manager reads twenty identical warnings as twenty problems rather than the one
     * configuration step it is.
     */
    public function test_the_response_says_whether_a_mail_provider_exists_at_all(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/settings/notifications/team')->assertOk()
            ->assertJsonPath('data.email_provider_configured', false);
    }

    /** Being on a manager's recipient list is shown, because it explains mail nobody chose. */
    public function test_a_person_on_a_recipient_list_is_marked_as_arranged(): void
    {
        DB::table('notification_recipients')->insert([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'project_id' => (string) $this->alpha->id,
            'user_id' => $this->scoped->id,
            'category' => 'budget',
            'created_by' => $this->owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($this->board()['scoped@tn.test']['arranged_by_manager']);
        $this->assertFalse($this->board()['owner@tn.test']['arranged_by_manager']);
    }

    /** Another workspace's ledger rows are not this workspace's answer. */
    public function test_a_digest_from_another_workspace_is_not_reported_here(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-team-notif', 'status' => 'active']);
        $this->prefer($this->scoped, ['digests' => ['daily' => true, 'weekly' => false, 'alerts' => false]]);

        DB::table('digest_sends')->insert([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => (string) $other->id,
            'user_id' => $this->scoped->id, 'kind' => 'daily', 'period_key' => '2026-08-07',
            'status' => 'sent', 'attempts' => 1, 'sent_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('never_sent', $this->board()['scoped@tn.test']['state']);
    }

    /** What somebody receives is the same answer their own preferences screen gives them. */
    public function test_the_categories_shown_are_the_ones_that_person_would_actually_receive(): void
    {
        $this->prefer($this->scoped, [
            'categories' => ['budget' => ['in_app' => true, 'email' => false]],
        ]);

        $row = $this->board()['scoped@tn.test'];

        $this->assertNotContains('budget', $row['categories'], 'a switched-off category was listed as received');
        $this->assertContains('integrations', $row['categories']);
        // `account` is never listed: everybody gets those, so printing them makes every row identical.
        $this->assertNotContains('account', $row['categories']);
    }
}
