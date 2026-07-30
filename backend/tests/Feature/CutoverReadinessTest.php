<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Identity\Actions\BackfillClientPortalIdentities;
use App\Domains\Identity\Models\PortalIdentityConflict;
use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cutover-readiness board (PORTAL-AUTH-001 step 5).
 *
 * `ready` must be a MEASUREMENT, not a judgement — the failure this guards against is somebody
 * deciding the migration "looks done" and deleting the token engine while a client's only session
 * still depends on it. Those clients have no password; signing them out is not recoverable by them.
 *
 * So each of the three conditions is asserted to block on its own, and the mismatches are NAMED:
 * "3 disagreements" tells nobody whose portal is about to change.
 */
final class CutoverReadinessTest extends TestCase
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

    /** Memoised: several tests read the board more than once, and each read signs the owner in. */
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
        $user->forceFill(['is_platform_admin' => true, 'tenant_id' => null])->save();

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

    private function request(ClientWorkspace $space, string $email): void
    {
        ExternalRequest::create([
            'tenant_id' => $this->agency->id,
            'reference' => 'REQ-'.strtoupper(bin2hex(random_bytes(4))),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Client', 'contact_email' => $email,
            'contact_phone' => '+966500000001', 'client_id' => $space->id, 'submitted_at' => now(),
        ]);
    }

    private function token(string $email, ?\DateTimeInterface $expires = null, bool $revoked = false): ClientPortalToken
    {
        return ClientPortalToken::create([
            'tenant_id' => $this->agency->id,
            'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
            'contact_email' => $email,
            'expires_at' => $expires ?? now()->addDays(14),
            'revoked_at' => $revoked ? now() : null,
        ]);
    }

    private function board(): array
    {
        return $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/cutover-readiness')->assertOk()->json('data');
    }

    /** The clean case: everything migrated, nothing live on the old engine. */
    public function test_it_reports_ready_only_when_all_three_conditions_are_clear(): void
    {
        $space = $this->space('Acme');
        $this->request($space, 'done@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        $board = $this->board();

        $this->assertTrue($board['ready']);
        $this->assertSame([], $board['blockers']);
        $this->assertSame(0, $board['open_conflicts']);
        $this->assertSame(0, $board['legacy_sessions']);
        $this->assertSame(0, $board['parity']['mismatched']);
    }

    /** An open conflict blocks on its own — that is a person nobody has decided about. */
    public function test_an_open_conflict_blocks(): void
    {
        PortalIdentityConflict::create([
            'tenant_id' => $this->agency->id, 'contact_email' => 'stuck@test.dev',
            'reason' => 'email_belongs_to_staff', 'client_ids' => [],
        ]);

        $board = $this->board();

        $this->assertFalse($board['ready']);
        $this->assertSame(1, $board['open_conflicts']);
        $this->assertStringContainsString('conflict', $board['blockers'][0]);
    }

    /** A live token blocks: its holder has no password, so signing them out is not recoverable. */
    public function test_a_live_legacy_session_blocks(): void
    {
        $space = $this->space('Acme');
        $this->request($space, 'live@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();
        $this->token('live@test.dev');

        $board = $this->board();

        $this->assertFalse($board['ready']);
        $this->assertSame(1, $board['legacy_sessions']);
        $this->assertSame('live@test.dev', $board['legacy_holders'][0]['contact']);
        // Marked, because a holder WITH a membership upgrades on next sign-in; one without needs a
        // conflict resolved first, and those are different problems.
        $this->assertTrue($board['legacy_holders'][0]['has_membership']);
    }

    /** An expired or revoked token authenticates nobody, so it blocks nothing. */
    public function test_expired_and_revoked_tokens_do_not_block(): void
    {
        $space = $this->space('Acme');
        $this->request($space, 'old@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        $this->token('old@test.dev', now()->subDay());
        $this->token('old@test.dev', null, revoked: true);

        $board = $this->board();

        $this->assertSame(0, $board['legacy_sessions']);
        $this->assertTrue($board['ready']);
    }

    /**
     * The subtle one. Both engines are live and a client's spaces changed since the backfill, so
     * they now disagree — cutting over would silently change what that person sees.
     */
    public function test_a_parity_disagreement_blocks_and_names_the_contact(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'drifted@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        // A second space arrives AFTER the backfill and nobody re-ran it.
        $beta = $this->space('Beta');
        $this->request($beta, 'drifted@test.dev');
        $this->token('drifted@test.dev');

        $board = $this->board();

        $this->assertFalse($board['ready']);
        $this->assertSame(1, $board['parity']['mismatched']);
        $this->assertSame('drifted@test.dev', $board['parity']['mismatches'][0]['contact']);
        // Both sides shown, so the reader can see WHAT would change rather than that something would.
        $this->assertCount(1, $board['parity']['mismatches'][0]['membership']);
        $this->assertCount(2, $board['parity']['mismatches'][0]['token']);
    }

    /** …and re-running the backfill resolves that drift, which the board then reflects. */
    public function test_re_running_the_backfill_clears_a_drift(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'fixed@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        $beta = $this->space('Beta');
        $this->request($beta, 'fixed@test.dev');
        $this->token('fixed@test.dev');

        $this->assertSame(1, $this->board()['parity']['mismatched']);

        app(BackfillClientPortalIdentities::class)->execute();

        $this->assertSame(0, $this->board()['parity']['mismatched']);
    }

    /** A token holder with no membership at all is listed as such — they need a conflict resolved. */
    public function test_a_token_holder_without_a_membership_is_flagged(): void
    {
        $space = $this->space('Acme');
        $this->request($space, 'unmigrated@test.dev');
        // Deliberately NOT backfilled.
        $this->token('unmigrated@test.dev');

        $holder = $this->board()['legacy_holders'][0];

        $this->assertFalse($holder['has_membership']);
    }

    /** Running the check records when it ran, so a stale green light is visible as stale. */
    public function test_the_check_records_when_it_last_ran(): void
    {
        $this->assertNotNull($this->board()['last_checked_at']);
    }

    /** The board is evidence only — there is no endpoint that performs the cutover. */
    public function test_there_is_no_endpoint_that_performs_the_cutover(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/admin/cutover-readiness')->assertStatus(405);
    }

    public function test_the_board_is_closed_to_everyone_but_the_platform_owner(): void
    {
        $user = User::create([
            'tenant_id' => $this->agency->id, 'name' => 'Staff', 'email' => 'staff@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/cutover-readiness')->assertForbidden();
    }

    /** Its own case: `actingAs` persists for the rest of a test method. */
    public function test_the_board_requires_a_session(): void
    {
        $this->getJson('/api/v1/admin/cutover-readiness')->assertUnauthorized();
    }

    /** Revoking a membership must show up as a parity change rather than passing quietly. */
    public function test_a_revoked_membership_shows_as_a_holder_without_one(): void
    {
        $space = $this->space('Acme');
        $this->request($space, 'revoked@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();
        $this->token('revoked@test.dev');

        $user = User::where('email', 'revoked@test.dev')->firstOrFail();
        Membership::where('user_id', $user->id)->where('portal', Portal::ClientPortal->value)
            ->update(['status' => 'revoked']);

        $this->assertFalse($this->board()['legacy_holders'][0]['has_membership']);
    }
}
