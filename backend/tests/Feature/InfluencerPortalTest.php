<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Influencer & UGC marketing (INFL-001).
 *
 * Two boundaries are held here, and they are deliberately different from each other:
 *
 *   the ROSTER is tenant-wide — a creator is not owned by a client, and hiding the roster from a
 *   scoped account manager would only make them re-add people the agency already works with;
 *
 *   COLLABORATIONS carry the client, so they narrow with the same client-scope ceiling every other
 *   client-bound surface uses.
 *
 * And one that is about money rather than access: what the creator is paid, and the margin between
 * that and what the client is billed, is a separate permission from reading the work at all.
 */
final class InfluencerPortalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();
        // The sub-system under test ships switched off (INFL-OFF-001). These tests are about whether
        // it WORKS, not about whether the platform is currently selling it.
        $this->withInfluencersEnabled();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create([
            'name' => 'Creator Agency', 'slug' => 'creator-agency', 'status' => 'active', 'account_type' => 'agency',
        ]);
        $this->holdingTenant((string) $this->agency->id);
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->agency->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function creator(string $name, ?string $handle = null): Influencer
    {
        return Influencer::create([
            'tenant_id' => $this->agency->id, 'name' => $name, 'handle' => $handle,
            'primary_platform' => 'instagram', 'followers' => 120_000, 'engagement_rate' => 4.25,
            'status' => 'active', 'internal_notes' => 'Asked for double last time.',
        ]);
    }

    private function collaboration(Influencer $creator, ?ClientWorkspace $client, array $over = []): InfluencerCollaboration
    {
        return InfluencerCollaboration::create(array_merge([
            'tenant_id' => $this->agency->id,
            'influencer_id' => $creator->id,
            'client_workspace_id' => $client?->id,
            'title' => 'Launch push',
            'status' => 'active',
            'currency' => 'SAR',
            'agreed_fee' => 25000,
            'influencer_fee' => 18000,
        ], $over));
    }

    /** @param  list<string>  $permissions */
    private function operator(string $email, array $permissions, ?array $clientScope = null, Portal $portal = Portal::Influencers): User
    {
        $user = User::create([
            'name' => 'Op', 'email' => $email,
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: $portal, role: 'member', clientScopeIds: $clientScope,
        ));

        return $user;
    }

    /** The roster is tenant-wide: a scoped manager still sees everyone the agency works with. */
    public function test_the_roster_is_not_narrowed_by_client_scope(): void
    {
        $mine = $this->client('Mine');
        $this->creator('Layla', 'layla');
        $this->creator('Omar', 'omar');

        $scoped = $this->operator('scoped@infl.dev', ['influencers.view'], [(string) $mine->id]);

        $this->actingAs($scoped, 'sanctum')->getJson('/api/v1/influencers/roster')
            ->assertOk()
            ->assertJsonCount(2, 'data.influencers');
    }

    /** Contact details and private notes are for whoever runs the roster, not everyone who reads it. */
    public function test_private_roster_fields_are_withheld_from_a_read_only_operator(): void
    {
        $creator = $this->creator('Layla', 'layla2');
        $creator->update(['contact_email' => 'layla@agent.test']);

        $reader = $this->operator('reader@infl.dev', ['influencers.view']);
        $manager = $this->operator('manager@infl.dev', ['influencers.view', 'influencers.manage']);

        $read = $this->actingAs($reader, 'sanctum')->getJson('/api/v1/influencers/roster')->assertOk();
        $this->assertArrayNotHasKey('internal_notes', $read->json('data.influencers.0'));
        $this->assertArrayNotHasKey('contact_email', $read->json('data.influencers.0'));

        $managed = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/influencers/roster')->assertOk();
        $this->assertSame('layla@agent.test', $managed->json('data.influencers.0.contact_email'));
    }

    /** Collaborations DO narrow — they carry the client, so the existing ceiling applies. */
    public function test_collaborations_are_narrowed_by_client_scope(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs');
        $creator = $this->creator('Layla', 'layla3');
        $this->collaboration($creator, $mine, ['title' => 'Mine work']);
        $this->collaboration($creator, $theirs, ['title' => 'Theirs work']);

        $scoped = $this->operator('scoped2@infl.dev', ['influencers.view'], [(string) $mine->id]);

        $response = $this->actingAs($scoped, 'sanctum')->getJson('/api/v1/influencers/collaborations')->assertOk();

        $titles = array_column($response->json('data.collaborations'), 'title');
        $this->assertSame(['Mine work'], $titles);
    }

    /** Agency-internal work has no client, and must not vanish for a scoped operator. */
    public function test_a_collaboration_with_no_client_stays_visible_to_a_scoped_operator(): void
    {
        $mine = $this->client('Mine');
        $creator = $this->creator('Layla', 'layla4');
        $this->collaboration($creator, null, ['title' => 'Our own brand']);

        $scoped = $this->operator('scoped3@infl.dev', ['influencers.view'], [(string) $mine->id]);

        $titles = array_column(
            $this->actingAs($scoped, 'sanctum')->getJson('/api/v1/influencers/collaborations')
                ->assertOk()->json('data.collaborations'),
            'title',
        );
        $this->assertSame(['Our own brand'], $titles);
    }

    /** A collaboration outside the ceiling is NOT FOUND — the same answer as one that never existed. */
    public function test_a_collaboration_outside_the_ceiling_is_not_found(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs');
        $creator = $this->creator('Layla', 'layla5');
        $hidden = $this->collaboration($creator, $theirs);

        $scoped = $this->operator('scoped4@infl.dev', ['influencers.view'], [(string) $mine->id]);

        $this->actingAs($scoped, 'sanctum')
            ->getJson("/api/v1/influencers/collaborations/{$hidden->id}")
            ->assertNotFound();
    }

    /**
     * What the creator is paid, and the margin, is a separate secret from the work itself. Withheld
     * means ABSENT — a zero or a rounded figure could be read as the truth or worked backwards.
     */
    public function test_creator_fee_and_margin_need_their_own_permission(): void
    {
        $client = $this->client('Acme');
        $creator = $this->creator('Layla', 'layla6');
        $this->collaboration($creator, $client);

        $reader = $this->operator('nocosts@infl.dev', ['influencers.view'], [(string) $client->id]);
        $row = $this->actingAs($reader, 'sanctum')->getJson('/api/v1/influencers/collaborations')
            ->assertOk()->json('data.collaborations.0');

        // Billed to the client — the client sees this on an invoice, so it is not a secret.
        $this->assertSame('25000.00', $row['agreed_fee']);
        $this->assertArrayNotHasKey('influencer_fee', $row);
        $this->assertArrayNotHasKey('margin', $row);
        $this->assertArrayNotHasKey('internal_notes', $row);

        $finance = $this->operator('costs@infl.dev', ['influencers.view', 'influencers.view_costs'], [(string) $client->id]);
        $full = $this->actingAs($finance, 'sanctum')->getJson('/api/v1/influencers/collaborations')
            ->assertOk()->json('data.collaborations.0');

        $this->assertSame('18000.00', $full['influencer_fee']);
        $this->assertSame('7000.00', $full['margin']);
    }

    /** A margin from one known figure would be a guess presented as a number. */
    public function test_the_margin_is_null_unless_both_figures_are_known(): void
    {
        $creator = $this->creator('Layla', 'layla7');
        $this->collaboration($creator, null, ['influencer_fee' => null]);

        $finance = $this->operator('costs2@infl.dev', ['influencers.view', 'influencers.view_costs']);

        $this->actingAs($finance, 'sanctum')->getJson('/api/v1/influencers/collaborations')
            ->assertOk()->assertJsonPath('data.collaborations.0.margin', null);
    }

    /** Creating work for a client the caller cannot reach would hand them that client's name. */
    public function test_a_collaboration_cannot_be_created_for_an_unreachable_client(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs');
        $creator = $this->creator('Layla', 'layla8');

        $scoped = $this->operator('scoped5@infl.dev', ['influencers.view', 'influencers.manage'], [(string) $mine->id]);

        $this->actingAs($scoped, 'sanctum')->postJson('/api/v1/influencers/collaborations', [
            'influencer_id' => (string) $creator->id,
            'client_workspace_id' => (string) $theirs->id,
            'title' => 'Sneaky',
        ])->assertForbidden();
    }

    /** Progress is per deliverable, because one status cannot say "two of three are live". */
    public function test_progress_counts_published_and_overdue_deliverables(): void
    {
        $creator = $this->creator('Layla', 'layla9');
        $collab = $this->collaboration($creator, null);

        $manager = $this->operator('mgr2@infl.dev', ['influencers.view', 'influencers.manage']);

        foreach (['reel', 'story', 'post'] as $type) {
            $this->actingAs($manager, 'sanctum')
                ->postJson("/api/v1/influencers/collaborations/{$collab->id}/deliverables", [
                    'type' => $type, 'platform' => 'instagram', 'due_on' => now()->subDay()->toDateString(),
                ])->assertCreated();
        }

        $ids = array_column(
            $this->actingAs($manager, 'sanctum')->getJson("/api/v1/influencers/collaborations/{$collab->id}")
                ->assertOk()->json('data.collaboration.deliverables'),
            'id',
        );

        $response = $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/influencers/collaborations/{$collab->id}/deliverables/{$ids[0]}", [
                'status' => 'published', 'submitted_url' => 'https://example.test/p/1',
            ])->assertOk();

        $this->assertSame(3, $response->json('data.collaboration.progress.total'));
        $this->assertSame(1, $response->json('data.collaboration.progress.published'));
        // The published one is no longer late; the two still owed are.
        $this->assertSame(2, $response->json('data.collaboration.progress.overdue'));
    }

    /** The timestamp comes from the status, so "what is late?" stays answerable. */
    public function test_publishing_stamps_the_time_rather_than_trusting_the_caller(): void
    {
        $creator = $this->creator('Layla', 'layla10');
        $collab = $this->collaboration($creator, null);
        $manager = $this->operator('mgr3@infl.dev', ['influencers.view', 'influencers.manage']);

        $created = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$collab->id}/deliverables", ['type' => 'reel'])
            ->assertCreated();
        $id = $created->json('data.collaboration.deliverables.0.id');

        $response = $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/influencers/collaborations/{$collab->id}/deliverables/{$id}", ['status' => 'published'])
            ->assertOk();

        $this->assertNotNull($response->json('data.collaboration.deliverables.0.published_at'));
    }

    /** The portal gate: an advertiser membership in the same tenant does not open this portal. */
    public function test_an_advertiser_membership_cannot_reach_the_influencers_portal(): void
    {
        $user = $this->operator('adv@infl.dev', ['influencers.view', 'influencers.manage'], null, Portal::App);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/influencers/roster')->assertForbidden();
    }

    public function test_the_portal_needs_its_permission_and_a_session(): void
    {
        $noPerm = $this->operator('noperm@infl.dev', ['clients.view']);
        $this->actingAs($noPerm, 'sanctum')->getJson('/api/v1/influencers/roster')->assertForbidden();

        $reader = $this->operator('readonly@infl.dev', ['influencers.view']);
        $this->actingAs($reader, 'sanctum')->postJson('/api/v1/influencers/roster', ['name' => 'X'])->assertForbidden();
    }

    public function test_the_portal_needs_a_session(): void
    {
        $this->getJson('/api/v1/influencers/roster')->assertUnauthorized();
    }

    /** A creator from another tenant is invisible, not merely unlisted. */
    public function test_the_roster_never_crosses_tenants(): void
    {
        $other = Tenant::create([
            'name' => 'Other', 'slug' => 'other-creators-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
        ]);
        $this->assertingAcrossTenants(function () use ($other): void {
            Influencer::create([
                'tenant_id' => $other->id, 'name' => 'Foreign creator', 'handle' => 'foreign', 'status' => 'active',
            ]);
        });
        $this->creator('Ours', 'ours');

        $reader = $this->operator('tenantcheck@infl.dev', ['influencers.view']);

        $names = array_column(
            $this->actingAs($reader, 'sanctum')->getJson('/api/v1/influencers/roster')->assertOk()->json('data.influencers'),
            'name',
        );
        $this->assertSame(['Ours'], $names);
    }

    /** Unrestricted access is the permission, so a holder sees every client's collaborations. */
    public function test_an_unrestricted_operator_sees_every_clients_collaborations(): void
    {
        $a = $this->client('A');
        $b = $this->client('B');
        $creator = $this->creator('Layla', 'layla11');
        $this->collaboration($creator, $a);
        $this->collaboration($creator, $b);

        $owner = $this->operator('owner@infl.dev', ['influencers.view', ClientScopeResolver::ALL_CLIENTS]);

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/influencers/collaborations')
            ->assertOk()->assertJsonCount(2, 'data.collaborations');
    }
}
