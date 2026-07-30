<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Branding\Models\BrandingSetting;
use App\Domains\Branding\Services\BrandingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agency white-label per client space (AGENCY-005).
 *
 * Branding is what the CLIENT sees, which makes two questions security questions rather than
 * cosmetic ones:
 *
 *   Who may set a client's brand? Only someone who can reach that client. `scope_id` arrived as a
 *   bare uuid with no ownership check, so a scoped operator could dress a client they have no access
 *   to — and a uuid from another tenant was accepted outright.
 *
 *   Whose brand does a client see? Their own space's, resolved from THEIR session. A parameter would
 *   let one client ask for another's.
 */
final class AgencyWhiteLabelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->agency = Tenant::create([
            'name' => 'Brand Agency', 'slug' => 'brand-agency', 'status' => 'active',
            'account_type' => 'agency', 'is_default_portal' => true,
        ]);
        app(TenantContext::class)->setTenantId((string) $this->agency->id);
        $this->holdingTenant((string) $this->agency->id);
    }

    private function client(string $name, ?Tenant $tenant = null): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => ($tenant ?? $this->agency)->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function operator(string $email, array $permissions, ?array $clientScope = null): User
    {
        $user = User::create([
            'name' => 'Op', 'email' => $email,
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: Portal::Agency, role: 'member', clientScopeIds: $clientScope,
        ));

        return $user;
    }

    private function contactIn(ClientWorkspace $space, string $email): void
    {
        ExternalRequest::create([
            'tenant_id' => $this->agency->id,
            'reference' => 'REQ-'.strtoupper(substr(md5($email.$space->id), 0, 8)),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Client', 'contact_email' => $email, 'contact_phone' => '+966500000001',
            'client_id' => $space->id, 'submitted_at' => now(),
        ]);
    }

    private function portalLogin(string $email): string
    {
        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => $email])
            ->assertCreated();

        return $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk()->json('data.dev_token');
    }

    /** @return array<string,string> */
    private function auth(string $token, ?string $space = null): array
    {
        return array_filter(['X-Client-Token' => $token, 'X-Client-Space' => $space]);
    }

    /** An operator who can reach the client may dress it. */
    public function test_an_operator_can_brand_a_client_they_can_reach(): void
    {
        $mine = $this->client('Mine');
        $operator = $this->operator('lead@brand.dev', ['branding.view', 'branding.manage', 'clients.view'], [(string) $mine->id]);

        $this->actingAs($operator, 'sanctum')->putJson('/api/v1/branding/settings', [
            'scope' => 'client', 'scope_id' => (string) $mine->id,
            'colors' => ['primary' => '#123456'], 'white_label' => true,
        ])->assertOk();

        $this->assertSame(['primary' => '#123456'], BrandingSetting::query()
            ->where('scope', 'client')->where('scope_id', $mine->id)->firstOrFail()->colors);
    }

    /**
     * The hole this closes. Branding is what the client SEES, so setting it for a client outside the
     * ceiling is editing a client-facing surface you have no access to.
     */
    public function test_an_operator_cannot_brand_a_client_outside_their_ceiling(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs');
        $operator = $this->operator('scoped@brand.dev', ['branding.view', 'branding.manage', 'clients.view'], [(string) $mine->id]);

        $this->actingAs($operator, 'sanctum')->putJson('/api/v1/branding/settings', [
            'scope' => 'client', 'scope_id' => (string) $theirs->id, 'colors' => ['primary' => '#000000'],
        ])->assertNotFound();

        $this->assertDatabaseMissing('branding_settings', ['scope' => 'client', 'scope_id' => $theirs->id]);
    }

    /** A client id from another tenant was accepted outright — it is now simply not found. */
    public function test_a_client_from_another_tenant_cannot_be_branded(): void
    {
        $other = Tenant::create([
            'name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
        ]);
        // Built while the context points at the OTHER tenant, so the row is genuinely theirs rather
        // than one of ours wearing their id.
        $context = app(TenantContext::class);
        $context->setTenantId((string) $other->id);
        $foreignId = (string) $this->client('Foreign', $other)->id;
        $context->setTenantId((string) $this->agency->id);

        $owner = $this->operator('owner@brand.dev', [
            'branding.view', 'branding.manage', 'clients.view', ClientScopeResolver::ALL_CLIENTS,
        ]);

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/branding/settings', [
            'scope' => 'client', 'scope_id' => $foreignId, 'colors' => ['primary' => '#fff'],
        ])->assertNotFound();
    }

    /** A client-scoped brand with no client named is a contradiction, not a tenant-wide default. */
    public function test_a_client_scope_without_a_client_is_refused(): void
    {
        $owner = $this->operator('owner2@brand.dev', [
            'branding.view', 'branding.manage', 'clients.view', ClientScopeResolver::ALL_CLIENTS,
        ]);

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/branding/settings', [
            'scope' => 'client', 'scope_id' => null, 'colors' => ['primary' => '#fff'],
        ])->assertStatus(422);
    }

    /** The client sees THEIR space's brand, resolved from their own session. */
    public function test_a_client_sees_the_brand_set_for_their_own_space(): void
    {
        $alpha = $this->client('Alpha');
        $this->contactIn($alpha, 'lead@alpha.test');

        app(BrandingService::class)
            ->saveSettings('client', (string) $alpha->id, ['colors' => ['primary' => '#AA0000'], 'white_label' => true]);

        $token = $this->portalLogin('lead@alpha.test');

        $data = $this->withHeaders($this->auth($token, $alpha->slug))
            ->getJson('/api/v1/client/branding')->assertOk()->json('data');

        $this->assertSame('#AA0000', $data['colors']['primary']);
        $this->assertSame('Alpha', $data['space']['name']);
        // Named for what it is: a stored preference, not a capability this endpoint could grant.
        $this->assertTrue($data['white_label_requested']);
    }

    /**
     * Two brands, one person: each space answers with its own. This is the whole point — a contact
     * on two of an agency's clients must not see one brand while reading the other's invoices.
     */
    public function test_each_space_answers_with_its_own_brand(): void
    {
        $alpha = $this->client('Alpha');
        $beta = $this->client('Beta');
        $this->contactIn($alpha, 'lead@both.test');
        $this->contactIn($beta, 'lead@both.test');

        $branding = app(BrandingService::class);
        $branding->saveSettings('client', (string) $alpha->id, ['colors' => ['primary' => '#AA0000']]);
        $branding->saveSettings('client', (string) $beta->id, ['colors' => ['primary' => '#0000BB']]);

        $token = $this->portalLogin('lead@both.test');

        $this->withHeaders($this->auth($token, $alpha->slug))->getJson('/api/v1/client/branding')
            ->assertOk()->assertJsonPath('data.colors.primary', '#AA0000');

        $this->withHeaders($this->auth($token, $beta->slug))->getJson('/api/v1/client/branding')
            ->assertOk()->assertJsonPath('data.colors.primary', '#0000BB');
    }

    /** Outside a space the tenant's own brand answers — the correct fallback for the merged view. */
    public function test_the_merged_view_falls_back_to_the_agencys_own_brand(): void
    {
        $alpha = $this->client('Alpha');
        $this->contactIn($alpha, 'solo@alpha.test');

        app(BrandingService::class)
            ->saveSettings('tenant', null, ['colors' => ['primary' => '#333333']]);

        $token = $this->portalLogin('solo@alpha.test');

        $data = $this->withHeaders($this->auth($token))->getJson('/api/v1/client/branding')
            ->assertOk()->json('data');

        $this->assertSame('#333333', $data['colors']['primary']);
        $this->assertNull($data['space']);
    }

    /** A client cannot ask for another space's brand by naming its slug. */
    public function test_a_client_cannot_read_another_spaces_brand(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs');
        $this->contactIn($mine, 'me@brand.test');
        $this->contactIn($theirs, 'other@brand.test');

        $token = $this->portalLogin('me@brand.test');

        $this->withHeaders($this->auth($token, $theirs->slug))
            ->getJson('/api/v1/client/branding')->assertNotFound();
    }

    public function test_the_branding_endpoint_needs_a_portal_session(): void
    {
        $this->getJson('/api/v1/client/branding')->assertUnauthorized();
    }
}
