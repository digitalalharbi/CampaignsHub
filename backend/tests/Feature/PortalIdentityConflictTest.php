<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Identity\Actions\BackfillClientPortalIdentities;
use App\Domains\Identity\Models\PortalIdentityConflict;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The conflict register and its resolutions (PORTAL-AUTH-001).
 *
 * Skipping keeps the migration safe; a skip that leaves no trace strands a person. Their portal
 * keeps working on the old engine, and on the day it is retired they cannot get in, with nobody
 * having been told. So every refusal is a row, and the register answers the cutover question
 * directly: is anything still open?
 */
final class PortalIdentityConflictTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->agency = Tenant::create([
            'name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'is_default_portal' => true,
        ]);
        app(TenantContext::class)->setTenantId((string) $this->agency->id);
        $this->holdingTenant((string) $this->agency->id);
    }

    /** Memoised: the owner-as-contact test calls this before AND after the backfill. */
    private ?User $owner = null;

    private function owner(): User
    {
        if ($this->owner !== null) {
            return $this->owner;
        }

        $user = User::create([
            'name' => 'Owner', 'email' => 'owner@platform.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $this->owner = $user;
    }

    private function space(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->agency->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    /** A staff member who is also named as a contact on a client's request — the real conflict. */
    private function staffAlsoAContact(string $email, ClientWorkspace $space): User
    {
        $staff = User::create([
            'name' => 'Both Hats', 'email' => $email,
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $staff, tenant: $this->agency, portal: Portal::Agency, role: 'member',
        ));

        ExternalRequest::create([
            'tenant_id' => $this->agency->id,
            'reference' => 'REQ-'.strtoupper(bin2hex(random_bytes(4))),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Both Hats', 'contact_email' => $email,
            'contact_phone' => '+966500000001', 'client_id' => $space->id, 'submitted_at' => now(),
        ]);

        return $staff;
    }

    public function test_a_refusal_is_recorded_rather_than_silently_skipped(): void
    {
        $space = $this->space('Acme');
        $this->staffAlsoAContact('both@agency.test', $space);

        app(BackfillClientPortalIdentities::class)->execute();

        $conflict = PortalIdentityConflict::query()->firstOrFail();
        $this->assertSame('email_belongs_to_staff', $conflict->reason);
        $this->assertSame('both@agency.test', $conflict->contact_email);
        // What they WOULD have been granted, so the resolver can see the consequence before choosing.
        $this->assertSame([(string) $space->id], $conflict->client_ids);
        $this->assertTrue($conflict->isOpen());
    }

    /** Re-running refreshes the same row. A register that duplicates becomes unreadable, then ignored. */
    public function test_re_running_does_not_pile_up_duplicate_conflicts(): void
    {
        $space = $this->space('Acme');
        $this->staffAlsoAContact('both@agency.test', $space);

        app(BackfillClientPortalIdentities::class)->execute();
        app(BackfillClientPortalIdentities::class)->execute();

        $this->assertSame(1, PortalIdentityConflict::query()->count());
    }

    /** A dry run must not write to the register either — it writes nothing at all. */
    public function test_a_dry_run_records_no_conflict(): void
    {
        $space = $this->space('Acme');
        $this->staffAlsoAContact('both@agency.test', $space);

        app(BackfillClientPortalIdentities::class)->execute(dryRun: true);

        $this->assertSame(0, PortalIdentityConflict::query()->count());
    }

    /** The register answers the cutover question outright rather than leaving it to be counted. */
    public function test_the_register_says_whether_the_legacy_engine_can_be_retired(): void
    {
        $space = $this->space('Acme');
        $this->staffAlsoAContact('both@agency.test', $space);
        app(BackfillClientPortalIdentities::class)->execute();

        $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/admin/portal-conflicts')
            ->assertOk()
            ->assertJsonPath('data.open', 1)
            ->assertJsonPath('data.safe_to_retire_legacy_engine', false);
    }

    /** `link`: the same person really does hold both roles. Additive — nothing they had is disturbed. */
    public function test_linking_grants_the_portal_membership_on_top_of_what_they_hold(): void
    {
        $space = $this->space('Acme');
        $staff = $this->staffAlsoAContact('both@agency.test', $space);
        app(BackfillClientPortalIdentities::class)->execute();
        $conflict = PortalIdentityConflict::query()->firstOrFail();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/portal-conflicts/{$conflict->id}", [
                'resolution' => 'link', 'note' => 'Confirmed with the agency: same person.',
            ])->assertOk();

        // They keep the agency membership AND gain the client-portal one.
        $this->assertTrue(Membership::where('user_id', $staff->id)->where('portal', Portal::Agency->value)->exists());
        $portal = Membership::where('user_id', $staff->id)
            ->where('portal', Portal::ClientPortal->value)->with('scopes')->firstOrFail();
        $this->assertSame([(string) $space->id], $portal->clientScopeIds());
    }

    /** …and only the spaces the conflict recorded, never a wider set. */
    public function test_linking_grants_exactly_the_recorded_spaces(): void
    {
        $mine = $this->space('Mine');
        $this->space('Theirs');
        $staff = $this->staffAlsoAContact('both@agency.test', $mine);
        app(BackfillClientPortalIdentities::class)->execute();
        $conflict = PortalIdentityConflict::query()->firstOrFail();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/portal-conflicts/{$conflict->id}", [
                'resolution' => 'link', 'note' => 'Same person.',
            ])->assertOk();

        $portal = Membership::where('user_id', $staff->id)
            ->where('portal', Portal::ClientPortal->value)->with('scopes')->firstOrFail();
        $this->assertCount(1, $portal->clientScopeIds());
    }

    /** `separate`: two people share an address. Grants NOTHING — that is a conversation, not a write. */
    public function test_separating_grants_nothing(): void
    {
        $space = $this->space('Acme');
        $staff = $this->staffAlsoAContact('both@agency.test', $space);
        app(BackfillClientPortalIdentities::class)->execute();
        $conflict = PortalIdentityConflict::query()->firstOrFail();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/portal-conflicts/{$conflict->id}", ['resolution' => 'separate'])
            ->assertOk();

        $this->assertSame(0, Membership::where('user_id', $staff->id)
            ->where('portal', Portal::ClientPortal->value)->count());
        $this->assertSame('separated', $conflict->fresh()->resolution);
    }

    /** Linking requires a stated reason: granting a staff account a client's view needs one on record. */
    public function test_linking_without_a_reason_is_refused(): void
    {
        $space = $this->space('Acme');
        $this->staffAlsoAContact('both@agency.test', $space);
        app(BackfillClientPortalIdentities::class)->execute();
        $conflict = PortalIdentityConflict::query()->firstOrFail();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/portal-conflicts/{$conflict->id}", ['resolution' => 'link'])
            ->assertStatus(422);
    }

    /** Resolving twice is refused rather than silently re-granting. */
    public function test_a_resolved_conflict_cannot_be_resolved_again(): void
    {
        $space = $this->space('Acme');
        $this->staffAlsoAContact('both@agency.test', $space);
        app(BackfillClientPortalIdentities::class)->execute();
        $conflict = PortalIdentityConflict::query()->firstOrFail();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/portal-conflicts/{$conflict->id}", ['resolution' => 'separate'])->assertOk();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/portal-conflicts/{$conflict->id}", ['resolution' => 'separate'])
            ->assertStatus(409);
    }

    /** The platform owner can never also be a portal client — refused, not resolved. */
    public function test_the_platform_owner_cannot_be_linked_as_a_client(): void
    {
        $space = $this->space('Acme');
        $owner = $this->owner();

        ExternalRequest::create([
            'tenant_id' => $this->agency->id,
            'reference' => 'REQ-OWNER', 'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Owner', 'contact_email' => $owner->email,
            'contact_phone' => '+966500000002', 'client_id' => $space->id, 'submitted_at' => now(),
        ]);
        app(BackfillClientPortalIdentities::class)->execute();
        $conflict = PortalIdentityConflict::query()->firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/admin/portal-conflicts/{$conflict->id}", [
                'resolution' => 'link', 'note' => 'Trying anyway.',
            ])->assertStatus(422);

        $this->assertSame(0, Membership::where('user_id', $owner->id)->count());
    }

    /** Resolutions are audited — this one grants access across a portal boundary. */
    public function test_resolving_is_audited_with_the_actor_and_the_reason(): void
    {
        $space = $this->space('Acme');
        $this->staffAlsoAContact('both@agency.test', $space);
        app(BackfillClientPortalIdentities::class)->execute();
        $conflict = PortalIdentityConflict::query()->firstOrFail();
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/admin/portal-conflicts/{$conflict->id}", [
                'resolution' => 'link', 'note' => 'Verified by phone.',
            ])->assertOk();

        $entry = AuditLog::query()
            ->where('action', 'platform.portal_conflict.resolved')->firstOrFail();
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertSame('Verified by phone.', $entry->reason);
    }

    public function test_the_register_is_closed_to_everyone_but_the_platform_owner(): void
    {
        $tenantUser = User::create([
            'name' => 'Staff', 'email' => 'staff@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $this->actingAs($tenantUser, 'sanctum')->getJson('/api/v1/admin/portal-conflicts')->assertForbidden();
    }
}
