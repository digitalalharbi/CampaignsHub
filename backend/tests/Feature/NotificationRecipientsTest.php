<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Services\AlertDispatcher;
use App\Domains\Notifications\Services\NotificationAudience;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * MAIL-010 — a recipient list is a request, and a membership is the authorisation.
 *
 * The failure this whole unit exists against is a specific and plausible one: somebody builds a
 * «who gets notified» screen, and it quietly becomes a second permission system. A manager adds a
 * colleague to a client's alerts, and the colleague starts receiving that client's spend in their
 * inbox — a place no later permission change can reach.
 *
 * So the tests are arranged around the two moments where that could happen: when the arrangement is
 * WRITTEN, and when the mail is SENT. The second is the one that matters, because access changes
 * after the first.
 */
final class NotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $alpha;

    private Project $beta;

    /** Reaches everything: the manager arranging the notifications. */
    private User $owner;

    /** Scoped to `alpha` only. */
    private User $scoped;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-recipients', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $one = ClientWorkspace::create(['name' => 'One', 'slug' => 'one-rec', 'mode' => 'managed']);
        $two = ClientWorkspace::create(['name' => 'Two', 'slug' => 'two-rec', 'mode' => 'managed']);
        $this->alpha = Project::create(['client_workspace_id' => $one->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->beta = Project::create(['client_workspace_id' => $two->id, 'name' => 'Beta', 'status' => 'active']);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-rec']);
        $ownerRole->givePermissionTo('clients.view_all');
        $ownerRole->givePermissionTo('settings.manage');

        $memberRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Member', 'slug' => 'member-rec']);

        $this->owner = $this->member('owner@rec.test', $ownerRole);
        $this->scoped = $this->member('scoped@rec.test', $memberRole, $this->alpha->id);
    }

    private function member(string $email, Role $role, ?string $projectScope = null): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $membership = Membership::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'portal' => 'agency', 'status' => 'active',
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

    private function arrange(User $user, ?string $projectId, ?string $category = null): void
    {
        DB::table('notification_recipients')->insert([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'project_id' => $projectId,
            'user_id' => $user->id,
            'category' => $category,
            'created_by' => $this->owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function audience(string $projectId, string $category = 'budget'): array
    {
        return array_map(
            static fn (User $u): string => (string) $u->email,
            app(NotificationAudience::class)->forProject((string) $this->tenant->id, $projectId, $category),
        );
    }

    /** The ordinary case, so the rest of the file is testing a working feature rather than a broken one. */
    public function test_somebody_arranged_for_a_project_they_can_reach_is_told(): void
    {
        $this->arrange($this->scoped, (string) $this->alpha->id);

        $this->assertSame(['scoped@rec.test'], $this->audience((string) $this->alpha->id));
    }

    /**
     * **The one that matters.** A row naming a project outside their reach mails them nothing.
     *
     * Written straight into the table, bypassing the controller entirely — because the controller's
     * refusal is a courtesy and this is the control. If the only thing standing between a scoped
     * member and another client's spend were a validation rule in an HTTP handler, then a seeder, a
     * console command or a future second write path would each be a way around it.
     */
    public function test_a_row_naming_a_project_outside_their_reach_sends_nothing(): void
    {
        $this->arrange($this->scoped, (string) $this->beta->id);

        $this->assertSame([], $this->audience((string) $this->beta->id));
    }

    /**
     * A blanket row is still bounded by the person's own ceiling.
     *
     * «Tell Sara about everything» means everything SARA can see, and this is where a naive
     * implementation leaks: the row has no project on it, so there is nothing obvious to check
     * against.
     */
    public function test_a_blanket_row_still_means_only_what_that_person_reaches(): void
    {
        $this->arrange($this->scoped, null);

        $this->assertSame(['scoped@rec.test'], $this->audience((string) $this->alpha->id));
        $this->assertSame([], $this->audience((string) $this->beta->id));
    }

    /**
     * Access revoked after the arrangement stops the mail, and leaves the arrangement alone.
     *
     * This is why eligibility is resolved at send time. Deleting the row on revocation would lose
     * the manager's intent; ignoring the revocation would keep mailing a client's figures to
     * somebody the product has already stopped showing them to.
     */
    public function test_a_revocation_after_the_arrangement_stops_the_mail_without_erasing_the_intent(): void
    {
        $this->arrange($this->scoped, (string) $this->alpha->id);
        $this->assertSame(['scoped@rec.test'], $this->audience((string) $this->alpha->id));

        Membership::query()->where('user_id', $this->scoped->id)->update(['status' => 'suspended']);

        $this->assertSame([], $this->audience((string) $this->alpha->id));
        $this->assertSame(1, DB::table('notification_recipients')->count(), 'the arrangement was erased');
    }

    /**
     * A manager decides who is INFORMED, never how somebody's inbox works.
     *
     * Being added to a list must not override the recipient's own switch. The alternative is a
     * product where turning something off in your settings does not turn it off.
     */
    public function test_a_category_the_recipient_switched_off_is_not_sent_to_them(): void
    {
        $this->arrange($this->scoped, (string) $this->alpha->id);

        DB::table('notification_preferences')->insert([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->scoped->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]),
            'categories' => json_encode(['budget' => ['in_app' => true, 'email' => false]]),
            'frequency' => 'realtime',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->audience((string) $this->alpha->id, 'budget'));
        // And a category they did not switch off still reaches them.
        $this->assertSame(['scoped@rec.test'], $this->audience((string) $this->alpha->id, 'reports'));
    }

    /** Email off is off, whatever the category map says underneath it. */
    public function test_switching_email_off_entirely_outranks_the_category_map(): void
    {
        $this->arrange($this->scoped, (string) $this->alpha->id);

        DB::table('notification_preferences')->insert([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->scoped->id,
            'channels' => json_encode(['in_app' => true, 'email' => false]),
            'categories' => json_encode(['budget' => ['in_app' => true, 'email' => true]]),
            'frequency' => 'realtime',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->audience((string) $this->alpha->id, 'budget'));
    }

    /** The API refuses the mistake at the moment it is made, rather than ignoring it later. */
    public function test_the_api_refuses_a_recipient_who_could_never_receive_it(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/v1/settings/notifications/recipients', [
                'user_id' => $this->scoped->id,
                'project_id' => (string) $this->beta->id,
                'category' => 'budget',
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('notification_recipients')->count());
    }

    /**
     * A scoped operator cannot arrange notifications for a project they cannot reach.
     *
     * Otherwise this form is a way to confirm that a client exists, one uuid at a time.
     */
    public function test_an_operator_cannot_arrange_a_project_outside_their_own_access(): void
    {
        $scopedManager = $this->member('mgr@rec.test', Role::where('slug', 'owner-rec')->firstOrFail());
        MembershipScope::create([
            'membership_id' => Membership::where('user_id', $scopedManager->id)->value('id'),
            'scope_type' => MembershipScope::TYPE_PROJECT,
            'scope_id' => $this->alpha->id,
        ]);

        $this->actingAs($scopedManager->fresh())
            ->postJson('/api/v1/settings/notifications/recipients', [
                'user_id' => $this->owner->id,
                'project_id' => (string) $this->beta->id,
            ])
            ->assertStatus(403);
    }

    /** Arranging other people is not something an ordinary member may do, or even look at. */
    public function test_the_screen_is_closed_to_somebody_without_settings_manage(): void
    {
        $this->actingAs($this->scoped)->getJson('/api/v1/settings/notifications/recipients')->assertStatus(403);
        $this->actingAs($this->scoped)->getJson('/api/v1/settings/notifications/recipients/assignable')->assertStatus(403);
        $this->actingAs($this->scoped)
            ->postJson('/api/v1/settings/notifications/recipients', ['user_id' => $this->owner->id])
            ->assertStatus(403);
    }

    /**
     * The form offers only what the store endpoint would accept.
     *
     * A picker that lists a project the actor cannot reach has already disclosed it, and one that
     * lists a person who would be refused wastes somebody's time on a choice that cannot work.
     */
    public function test_the_assignable_list_offers_only_the_actors_own_reach(): void
    {
        $scopedManager = $this->member('mgr2@rec.test', Role::where('slug', 'owner-rec')->firstOrFail());
        MembershipScope::create([
            'membership_id' => Membership::where('user_id', $scopedManager->id)->value('id'),
            'scope_type' => MembershipScope::TYPE_PROJECT,
            'scope_id' => $this->alpha->id,
        ]);

        $body = $this->actingAs($scopedManager->fresh())
            ->getJson('/api/v1/settings/notifications/recipients/assignable')
            ->assertOk()->json('data');

        $this->assertSame([(string) $this->alpha->id], array_column($body['projects'], 'id'));

        foreach ($body['people'] as $person) {
            $this->assertSame([(string) $this->alpha->id], $person['project_ids'], "{$person['email']} was offered beyond the actor's reach");
        }
    }

    /**
     * The listing says which arrangements are inert, and why.
     *
     * «Sara is on the list and is not being told» has to be answerable on the screen, or a manager
     * arranges something, watches nothing happen, and concludes the feature is broken.
     */
    public function test_the_listing_reports_an_arrangement_that_has_stopped_working(): void
    {
        $this->arrange($this->scoped, (string) $this->alpha->id, 'budget');
        Membership::query()->where('user_id', $this->scoped->id)->update(['status' => 'suspended']);

        $rows = $this->actingAs($this->owner)
            ->getJson('/api/v1/settings/notifications/recipients')
            ->assertOk()->json('data.recipients');

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['status']['eligible']);
        $this->assertSame('outside_their_access', $rows[0]['status']['reason']);
    }

    /**
     * The narrowing argument on the sweep is an INTERSECTION, proved directly.
     *
     * `AlertDispatcher::sweep()` takes a project list from the arrangement. Assigning it instead of
     * intersecting would turn that parameter into a way to mail somebody a project they cannot
     * reach — the single thing this whole design exists to prevent, and a one-character mistake.
     */
    public function test_the_sweeps_project_filter_cannot_reach_past_the_persons_own_ceiling(): void
    {
        $dispatcher = app(AlertDispatcher::class);

        $counts = $dispatcher->sweep(
            $this->scoped, (string) $this->tenant->id, now(), 'ar',
            // Beta is outside their membership. An assignment here would sweep it.
            [(string) $this->beta->id],
        );

        $this->assertSame(0, array_sum($counts), 'the sweep reached a project outside the ceiling');
    }

    /**
     * A manager cannot arrange somebody into a category they switched off.
     *
     * This is asserted on the SWEEP rather than on the resolver, because the alert path builds its
     * own recipient list and never reads the preferences table — so the property held by
     * `NotificationAudience` was not, on its own, a property of the product.
     */
    public function test_the_alert_sweep_respects_a_category_the_recipient_switched_off(): void
    {
        $this->arrange($this->scoped, (string) $this->alpha->id);

        DB::table('notification_preferences')->insert([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->scoped->id,
            'channels' => json_encode(['in_app' => true, 'email' => false]),
            'categories' => json_encode([]),
            'frequency' => 'realtime',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('notifications:send-alerts')->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('digest_sends')->where('user_id', $this->scoped->id)->count(),
            'an arrangement overrode the recipient’s own switch',
        );
    }

    /** An id from another workspace finds nothing rather than being found and then refused. */
    public function test_a_row_from_another_workspace_cannot_be_deleted_through_this_endpoint(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-recipients', 'status' => 'active']);
        $id = (string) Uuid::uuid4();
        DB::table('notification_recipients')->insert([
            'id' => $id, 'tenant_id' => (string) $other->id, 'project_id' => null,
            'user_id' => $this->owner->id, 'category' => null, 'created_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/settings/notifications/recipients/{$id}")
            ->assertOk();

        $this->assertSame(1, DB::table('notification_recipients')->where('id', $id)->count());
    }
}
