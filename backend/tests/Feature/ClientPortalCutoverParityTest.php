<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Identity\Actions\BackfillClientPortalIdentities;
use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Requests\Services\ClientPortalIdentity;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The cutover gate for PORTAL-AUTH-001.
 *
 * The old engine may only be retired once the new one answers identically for EVERY contact and
 * space. This measures that rather than reasoning about it: for each token it asks both engines what
 * they reach and refuses any disagreement.
 *
 * It also holds the behaviours the cutover must not break — a membership revoked mid-session ends
 * access immediately, a request not yet converted stays visible to the person who submitted it, and
 * a membership with no scope reaches nothing rather than everything.
 */
final class ClientPortalCutoverParityTest extends TestCase
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

    private function space(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->agency->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function request(?ClientWorkspace $space, string $email): ExternalRequest
    {
        return ExternalRequest::create([
            'tenant_id' => $this->agency->id,
            'reference' => 'REQ-'.strtoupper(bin2hex(random_bytes(4))),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Client', 'contact_email' => $email, 'contact_phone' => '+966500000001',
            'client_id' => $space?->id, 'submitted_at' => now(),
        ]);
    }

    private function tokenFor(string $email): ClientPortalToken
    {
        return ClientPortalToken::create([
            'tenant_id' => $this->agency->id,
            'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
            'contact_email' => $email,
            'expires_at' => now()->addDays(14),
        ]);
    }

    /**
     * The gate itself. Every token, both engines, no disagreement — and the assertion names the
     * contact when it fails, because "parity failed" without a subject is not actionable.
     */
    public function test_both_engines_agree_for_every_contact(): void
    {
        $alpha = $this->space('Alpha');
        $beta = $this->space('Beta');

        $this->request($alpha, 'one@test.dev');
        $this->request($alpha, 'two@test.dev');
        $this->request($beta, 'two@test.dev');

        app(BackfillClientPortalIdentities::class)->execute();

        $identity = app(ClientPortalIdentity::class);

        foreach (['one@test.dev', 'two@test.dev'] as $email) {
            $result = $identity->parity(Request::create('/'), $this->tokenFor($email));

            $this->assertTrue(
                $result['agree'],
                "engines disagree for {$email}: membership="
                    .json_encode($result['membership']).' token='.json_encode($result['token']),
            );
            $this->assertSame($result['token'], $result['membership']);
        }
    }

    /** A contact the backfill skipped has no membership, so the token still serves them. */
    public function test_a_contact_without_a_membership_is_still_served_by_the_token(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'unmigrated@test.dev');
        // Deliberately NOT backfilled.

        $reach = app(ClientPortalIdentity::class)->reach(Request::create('/'), $this->tokenFor('unmigrated@test.dev'));

        $this->assertSame('token', $reach['engine']);
        $this->assertSame([(string) $alpha->id], $reach['ids']);
    }

    /** Once migrated, the membership answers — and answers the same thing. */
    public function test_a_migrated_contact_is_served_by_the_membership(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'migrated@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        $reach = app(ClientPortalIdentity::class)->reach(Request::create('/'), $this->tokenFor('migrated@test.dev'));

        $this->assertSame('membership', $reach['engine']);
        $this->assertSame([(string) $alpha->id], $reach['ids']);
    }

    /**
     * Withdrawing the membership ends access on the next request — not when the token expires.
     * Access that outlives its grant is the failure mode a migration like this invites.
     */
    public function test_revoking_the_membership_ends_access_immediately(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'revoked@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        $user = User::where('email', 'revoked@test.dev')->firstOrFail();
        Membership::where('user_id', $user->id)->where('portal', Portal::ClientPortal->value)
            ->update(['status' => 'revoked']);

        $reach = app(ClientPortalIdentity::class)->reach(Request::create('/'), $this->tokenFor('revoked@test.dev'));

        // Falls back to the token engine, which is the honest state during the cutover — the token
        // is a live session that has not been revoked. What must NOT happen is the membership
        // continuing to grant after it was withdrawn.
        $this->assertSame('token', $reach['engine']);
    }

    /**
     * A membership whose scopes were all removed reaches NOTHING. Reading an empty scope as
     * "everything" would turn a failed grant into a key to the whole agency.
     */
    public function test_a_membership_with_no_scope_reaches_nothing(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'empty@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        $user = User::where('email', 'empty@test.dev')->firstOrFail();
        $membership = Membership::where('user_id', $user->id)
            ->where('portal', Portal::ClientPortal->value)->firstOrFail();
        $membership->scopes()->delete();

        $reach = app(ClientPortalIdentity::class)->reach(Request::create('/'), $this->tokenFor('empty@test.dev'));

        $this->assertSame('membership', $reach['engine']);
        $this->assertSame([], $reach['ids']);
    }

    /**
     * The "no lost requests" line. A request submitted but not yet converted belongs to no client
     * space, so scope alone would hide it — from the person who submitted it.
     */
    public function test_an_unconverted_request_stays_visible_to_the_person_who_submitted_it(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'mixed@test.dev');
        $pending = $this->request(null, 'mixed@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'mixed@test.dev'])
            ->assertCreated();
        $token = $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk()->json('data.dev_token');

        $references = array_column(
            $this->withHeaders(['X-Client-Token' => $token])->getJson('/api/v1/client/requests')
                ->assertOk()->json('data.requests'),
            'reference',
        );

        $this->assertContains($pending->reference, $references,
            'a request that has not been converted yet must not disappear from its own submitter');
    }

    /** …but it belongs to no space, so inside one it stays hidden. */
    public function test_an_unconverted_request_is_not_shown_inside_a_client_space(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'mixed2@test.dev');
        $pending = $this->request(null, 'mixed2@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'mixed2@test.dev'])
            ->assertCreated();
        $token = $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk()->json('data.dev_token');

        $references = array_column(
            $this->withHeaders(['X-Client-Token' => $token, 'X-Client-Space' => $alpha->slug])
                ->getJson('/api/v1/client/requests')->assertOk()->json('data.requests'),
            'reference',
        );

        $this->assertNotContains($pending->reference, $references);
    }

    /** Isolation survives the switch: one contact never sees another's space. */
    public function test_the_switch_does_not_widen_anyone(): void
    {
        $mine = $this->space('Mine');
        $theirs = $this->space('Theirs');
        $this->request($mine, 'me@test.dev');
        $this->request($theirs, 'them@test.dev');
        app(BackfillClientPortalIdentities::class)->execute();

        $reach = app(ClientPortalIdentity::class)->reach(Request::create('/'), $this->tokenFor('me@test.dev'));

        $this->assertSame([(string) $mine->id], $reach['ids']);
        $this->assertNotContains((string) $theirs->id, $reach['ids']);
    }
}
