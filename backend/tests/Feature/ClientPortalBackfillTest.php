<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Identity\Actions\BackfillClientPortalIdentities;
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
 * Step 1 of PORTAL-AUTH-001: minting identities for existing portal contacts.
 *
 * The one property that matters more than any other is this: **the membership's scope must equal
 * exactly what the portal computes for that contact today.** If it does not, the later cutover
 * silently changes what a client can see — and the change is invisible, because both answers look
 * like a normal portal. Several tests below assert that equality directly rather than asserting a
 * count.
 *
 * Everything else is fail-closed. A contact this cannot resolve confidently is skipped with a reason,
 * because a client portal that over-grants shows one customer another customer's invoices.
 */
final class ClientPortalBackfillTest extends TestCase
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
            'account_type' => 'agency',
            // The portal resolves its tenant from this flag, so the OTP login below needs it.
            'is_default_portal' => true,
        ]);
        app(TenantContext::class)->setTenantId((string) $this->agency->id);
        $this->holdingTenant((string) $this->agency->id);
    }

    private function space(string $name, ?Tenant $tenant = null): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => ($tenant ?? $this->agency)->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function request(ClientWorkspace $space, ?string $email, ?string $phone, ?Tenant $tenant = null): void
    {
        ExternalRequest::create([
            'tenant_id' => ($tenant ?? $this->agency)->id,
            'reference' => 'REQ-'.strtoupper(bin2hex(random_bytes(4))),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Real Name',
            'contact_email' => $email,
            'contact_phone' => $phone,
            'client_id' => $space->id,
            'submitted_at' => now(),
        ]);
    }

    private function backfill(bool $dryRun = false): array
    {
        return app(BackfillClientPortalIdentities::class)->execute($dryRun);
    }

    /** @return list<string> */
    private function scopeOf(string $email, ?Tenant $tenant = null): array
    {
        $user = User::where('email', $email)->firstOrFail();
        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', ($tenant ?? $this->agency)->id)
            ->where('portal', Portal::ClientPortal->value)
            ->with('scopes')->firstOrFail();

        $ids = $membership->clientScopeIds();
        sort($ids);

        return $ids;
    }

    public function test_a_contact_becomes_a_user_with_a_client_portal_membership(): void
    {
        $space = $this->space('Acme');
        $this->request($space, 'lead@acme.test', '+966500000001');

        $this->assertSame(1, $this->backfill()['granted']);

        $user = User::where('email', 'lead@acme.test')->firstOrFail();
        $this->assertSame('Real Name', $user->name);
        // Verified: accepting the portal's OTP already proved they hold the address, and demanding a
        // second confirmation would lock out every existing client on cutover day.
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame([(string) $space->id], $this->scopeOf('lead@acme.test'));
    }

    /**
     * The property the whole migration rests on: the granted scope equals what the portal computes
     * for that contact today. Asserted against the live endpoint, not against a number.
     */
    public function test_the_granted_scope_equals_what_the_portal_reaches_today(): void
    {
        $alpha = $this->space('Alpha');
        $beta = $this->space('Beta');
        $this->request($alpha, 'lead@both.test', '+966500000002');
        $this->request($beta, 'lead@both.test', '+966500000002');

        $this->backfill();

        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'lead@both.test'])
            ->assertCreated();
        $token = $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk()->json('data.dev_token');

        $reachable = collect(
            $this->withHeaders(['X-Client-Token' => $token])->getJson('/api/v1/client/spaces')
                ->assertOk()->json('data.spaces')
        )->pluck('id')->sort()->values()->all();

        $this->assertSame($reachable, $this->scopeOf('lead@both.test'),
            'the membership must reach exactly what the OTP session reaches, or the cutover changes what a client sees');
    }

    /** One person on two of an agency's clients is ONE membership with two scopes, not two rows. */
    public function test_a_contact_on_several_spaces_gets_one_membership_with_every_scope(): void
    {
        $alpha = $this->space('Alpha');
        $beta = $this->space('Beta');
        $this->request($alpha, 'multi@test.dev', '+966500000003');
        $this->request($beta, 'multi@test.dev', '+966500000003');

        $this->backfill();

        $user = User::where('email', 'multi@test.dev')->firstOrFail();
        $this->assertSame(1, Membership::where('user_id', $user->id)->count());
        $this->assertCount(2, $this->scopeOf('multi@test.dev'));
    }

    /** The same person as a client of two DIFFERENT agencies is two memberships, never merged reach. */
    public function test_the_same_person_at_two_agencies_gets_two_separate_memberships(): void
    {
        $other = Tenant::create([
            'name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
        ]);

        $mine = $this->space('Mine');
        $this->request($mine, 'shared@test.dev', '+966500000004');

        $context = app(TenantContext::class);
        $context->setTenantId((string) $other->id);
        $theirs = $this->space('Theirs', $other);
        $this->request($theirs, 'shared@test.dev', '+966500000004', $other);
        $context->setTenantId((string) $this->agency->id);

        $this->backfill();

        $user = User::where('email', 'shared@test.dev')->firstOrFail();
        $this->assertSame(2, Membership::where('user_id', $user->id)->count());
        $this->assertSame([(string) $mine->id], $this->scopeOf('shared@test.dev'));
        $this->assertSame([(string) $theirs->id], $this->scopeOf('shared@test.dev', $other));
    }

    /** Re-running must change nothing. This will be run more than once, by people who are unsure. */
    public function test_running_it_twice_changes_nothing(): void
    {
        $space = $this->space('Acme');
        $this->request($space, 'idem@test.dev', '+966500000005');

        $this->backfill();
        $usersAfterFirst = User::count();
        $membershipsAfterFirst = Membership::count();
        $scopeAfterFirst = $this->scopeOf('idem@test.dev');

        $second = $this->backfill();

        $this->assertSame(0, $second['granted']);
        // Reported as already-correct, NOT as skipped: a re-run leaving everything alone is success,
        // and calling it "skipped" told the operator 23 contacts had a problem when none did.
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame([], $second['skipped']);
        $this->assertSame($usersAfterFirst, User::count());
        $this->assertSame($membershipsAfterFirst, Membership::count());
        $this->assertSame($scopeAfterFirst, $this->scopeOf('idem@test.dev'));
    }

    /** A space added after the first run is picked up; the existing ones survive. */
    public function test_a_new_space_is_added_without_losing_the_old_ones(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'grow@test.dev', '+966500000006');
        $this->backfill();

        $beta = $this->space('Beta');
        $this->request($beta, 'grow@test.dev', '+966500000006');
        $this->backfill();

        $expected = [(string) $alpha->id, (string) $beta->id];
        sort($expected);
        $this->assertSame($expected, $this->scopeOf('grow@test.dev'));
    }

    /**
     * The dangerous collision. A contact email that is already a STAFF account must never be given a
     * client-portal membership: it would give an agency employee a client's view of their own agency,
     * and hand a client a foothold on staff surfaces.
     */
    public function test_an_email_belonging_to_staff_is_skipped_not_merged(): void
    {
        $space = $this->space('Acme');
        $staff = User::create([
            'name' => 'Employee', 'email' => 'staff@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $staff, tenant: $this->agency, portal: Portal::Agency, role: 'member',
        ));

        $this->request($space, 'staff@agency.test', '+966500000007');

        $result = $this->backfill();

        $this->assertSame(0, $result['granted']);
        $this->assertSame('email_belongs_to_staff', $result['skipped'][0]['reason']);
        $this->assertSame(0, Membership::where('user_id', $staff->id)
            ->where('portal', Portal::ClientPortal->value)->count());
    }

    /** The platform owner is staff too, and the most expensive account to hand out. */
    public function test_the_platform_owners_email_is_skipped(): void
    {
        $space = $this->space('Acme');
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password' => 'secret123']);
        $owner->forceFill(['is_platform_admin' => true])->save();

        $this->request($space, 'owner@platform.test', '+966500000008');

        $this->assertSame('email_belongs_to_staff', $this->backfill()['skipped'][0]['reason']);
    }

    /**
     * The phone-only guard is DEFENCE, not a live path: `external_requests.contact_email` is NOT
     * NULL, so a request without an address cannot be stored today. The branch stays because the
     * column's nullability is a schema decision that could change, and the day it does the backfill
     * must skip rather than invent an address nobody can sign in with. It is asserted at the unit
     * level instead of through the model, which the constraint refuses.
     */
    public function test_the_action_skips_a_contact_with_no_address(): void
    {
        $reflection = new \ReflectionMethod(BackfillClientPortalIdentities::class, 'one');
        $space = $this->space('Acme');

        $outcome = $reflection->invoke(app(BackfillClientPortalIdentities::class), [
            'email' => null,
            'phone' => '+966500000009',
            'tenant_id' => (string) $this->agency->id,
            'client_ids' => [(string) $space->id],
        ], false);

        $this->assertSame('phone_only_no_email', $outcome['outcome']);
        $this->assertSame(0, User::count());
    }

    /** Two contacts sharing a phone but with different emails are two people, not one. */
    public function test_a_shared_phone_does_not_merge_two_people(): void
    {
        $space = $this->space('Acme');
        $this->request($space, 'first@test.dev', '+966500000010');
        $this->request($space, 'second@test.dev', '+966500000010');

        $this->assertSame(2, $this->backfill()['granted']);
        $this->assertSame(2, User::whereIn('email', ['first@test.dev', 'second@test.dev'])->count());
    }

    /** Case and whitespace are not identity. Two spellings of one address are one person. */
    public function test_case_and_whitespace_in_an_address_do_not_create_two_people(): void
    {
        $alpha = $this->space('Alpha');
        $beta = $this->space('Beta');
        $this->request($alpha, 'Mixed@Test.dev', '+966500000011');
        $this->request($beta, ' mixed@test.dev ', '+966500000011');

        $this->assertSame(1, $this->backfill()['granted']);
        $this->assertCount(2, $this->scopeOf('mixed@test.dev'));
    }

    /** A request with no client space grants nothing — a membership reaching nothing is not access. */
    public function test_a_request_with_no_client_space_grants_nothing(): void
    {
        ExternalRequest::create([
            'tenant_id' => $this->agency->id,
            'reference' => 'REQ-NOSPACE',
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Unconverted', 'contact_email' => 'nospace@test.dev',
            'contact_phone' => '+966500000012', 'client_id' => null, 'submitted_at' => now(),
        ]);

        $this->assertSame(0, $this->backfill()['granted']);
        $this->assertSame(0, User::count());
    }

    /** A dry run reports and writes nothing — the first thing anyone should do with this. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $space = $this->space('Acme');
        $this->request($space, 'dry@test.dev', '+966500000013');

        $this->assertSame(1, $this->backfill(dryRun: true)['granted']);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Membership::count());
    }

    /** No membership is created without its scopes: the two are one transaction, or neither happens. */
    public function test_a_membership_never_exists_without_its_scopes(): void
    {
        $alpha = $this->space('Alpha');
        $this->request($alpha, 'atomic@test.dev', '+966500000014');
        $this->backfill();

        $memberships = Membership::where('portal', Portal::ClientPortal->value)->with('scopes')->get();

        $this->assertNotEmpty($memberships);
        foreach ($memberships as $membership) {
            $this->assertNotEmpty(
                $membership->clientScopeIds(),
                'a client-portal membership with no scope reaches nothing — it should not have been created',
            );
        }
    }
}
