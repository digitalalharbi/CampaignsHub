<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Branding\Models\BrandingAsset;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /** One uploaded mark for a space, distinct per space so the bytes can be told apart. */
    private function logoFor(ClientWorkspace $space): BrandingAsset
    {
        return app(BrandingService::class)->storeAsset(
            'client',
            (string) $space->id,
            'primary_horizontal',
            'any',
            UploadedFile::fake()->image('logo-'.$space->slug.'.png', 64, strlen($space->name) + 16),
        );
    }

    /** The path and query of an absolute URL, which is what the test client takes. */
    private function pathOf(string $url): string
    {
        $parts = parse_url($url);

        return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
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

    /**
     * The logo the portal hands a client is one the client can actually LOAD.
     *
     * `branding()` returned `branding/assets/{id}/file`, which sits behind `auth:sanctum` and
     * `portal:app,agency`. A portal contact holds a cookie session tied to a verified contact and is
     * not a Sanctum user, so that URL answered **401 — to the one audience it was handed to**. The
     * portal header renders an `<img>` from it and skips its own fallback precisely BECAUSE a logo
     * exists, so an agency that uploaded a client logo got a broken image where its brand should be.
     * BRANDING-HIERARCHY-001 forbids exactly that: never a broken image, never a blank header.
     *
     * Every existing test here read the COLOURS out of that payload. Following the URL is the only
     * assertion that could have caught this, which is why it is now the first one.
     */
    public function test_a_client_can_load_the_logo_the_portal_hands_them(): void
    {
        Storage::fake('local');

        $alpha = $this->client('Alpha');
        $this->contactIn($alpha, 'lead@logo.test');
        $this->logoFor($alpha);

        $token = $this->portalLogin('lead@logo.test');
        $headers = $this->auth($token, $alpha->slug);

        $logos = $this->withHeaders($headers)->getJson('/api/v1/client/branding')->assertOk()->json('data.logos');
        $this->assertNotEmpty($logos, 'the portal offered no logo at all');

        $this->withHeaders($headers)->get($this->pathOf((string) $logos[0]['url']))->assertOk();
    }

    /**
     * The URL carries a ROLE, never an id.
     *
     * This is the isolation property stated structurally rather than by trying ids and hoping they
     * are refused: with no identifier in the request, asking for another tenant's asset is not
     * expressible. The shared-report logo route reaches the same guarantee the same way.
     */
    public function test_the_logo_url_names_a_role_and_carries_no_asset_id(): void
    {
        Storage::fake('local');

        $alpha = $this->client('Alpha');
        $this->contactIn($alpha, 'lead@role.test');
        $asset = $this->logoFor($alpha);

        $token = $this->portalLogin('lead@role.test');
        $url = (string) $this->withHeaders($this->auth($token, $alpha->slug))
            ->getJson('/api/v1/client/branding')->assertOk()->json('data.logos.0.url');

        $this->assertStringContainsString('kind=primary_horizontal', $url);
        $this->assertStringNotContainsString((string) $asset->getKey(), $url, 'the URL carried an asset id');
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-/i', $url, 'the URL carried a uuid');
    }

    /**
     * A kind this space has no asset for is a 404 — never somebody else's mark.
     *
     * The tempting failure mode is to fall back through the hierarchy on a miss, which would serve
     * the AGENCY's logo under a request for the client's. The payload decides what exists; this route
     * only serves what the payload already named.
     */
    public function test_an_unknown_or_unset_kind_is_refused_rather_than_substituted(): void
    {
        Storage::fake('local');

        $alpha = $this->client('Alpha');
        $this->contactIn($alpha, 'lead@miss.test');
        $this->logoFor($alpha);

        $headers = $this->auth($this->portalLogin('lead@miss.test'), $alpha->slug);

        $this->withHeaders($headers)->get('/api/v1/client/branding/logo?kind=not_a_kind')->assertNotFound();
        $this->withHeaders($headers)->get('/api/v1/client/branding/logo?kind=email_header')->assertNotFound();
    }

    /** The bytes need a portal session, exactly as the payload does. */
    public function test_the_logo_route_needs_a_portal_session(): void
    {
        $this->get('/api/v1/client/branding/logo?kind=primary_horizontal')->assertUnauthorized();
    }

    /**
     * The name and the mark come from the SAME space.
     *
     * Two copies of the space-resolution rule is how a portal ends up showing one client's logo above
     * another client's name — which is worse than showing neither, because it is legible and wrong.
     * One contact on two spaces, asked twice, must get two different marks.
     */
    public function test_the_logo_follows_the_same_space_as_the_name(): void
    {
        Storage::fake('local');

        $alpha = $this->client('Alpha');
        $beta = $this->client('Beta');
        $this->contactIn($alpha, 'lead@two.test');
        $this->contactIn($beta, 'lead@two.test');
        $this->logoFor($alpha);
        $this->logoFor($beta);

        $token = $this->portalLogin('lead@two.test');

        $forAlpha = $this->withHeaders($this->auth($token, $alpha->slug))
            ->getJson('/api/v1/client/branding')->assertOk()->json('data');
        $forBeta = $this->withHeaders($this->auth($token, $beta->slug))
            ->getJson('/api/v1/client/branding')->assertOk()->json('data');

        $this->assertSame('Alpha', $forAlpha['space']['name']);
        $this->assertSame('Beta', $forBeta['space']['name']);

        // Same role, same URL — and the BYTES differ, because the space differs.
        $alphaBytes = $this->withHeaders($this->auth($token, $alpha->slug))
            ->get($this->pathOf((string) $forAlpha['logos'][0]['url']))->assertOk()->streamedContent();
        $betaBytes = $this->withHeaders($this->auth($token, $beta->slug))
            ->get($this->pathOf((string) $forBeta['logos'][0]['url']))->assertOk()->streamedContent();

        $this->assertNotSame($alphaBytes, $betaBytes, 'both spaces served the same bytes');
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

    /**
     * The merged view falls back to the agency's own brand — because no one client's may stand in.
     *
     * The fixture is TWO spaces, which is what «merged» means. It used to be one, and the case passed
     * for a reason unrelated to its name: branding read "no slug in the URL" as "no client at all".
     */
    public function test_the_merged_view_falls_back_to_the_agencys_own_brand(): void
    {
        $alpha = $this->client('Alpha');
        $beta = $this->client('Beta');
        $this->contactIn($alpha, 'lead@merged.test');
        $this->contactIn($beta, 'lead@merged.test');

        app(BrandingService::class)
            ->saveSettings('tenant', null, ['colors' => ['primary' => '#333333']]);

        $token = $this->portalLogin('lead@merged.test');

        $data = $this->withHeaders($this->auth($token))->getJson('/api/v1/client/branding')
            ->assertOk()->json('data');

        $this->assertSame('#333333', $data['colors']['primary']);
        $this->assertNull($data['space']);
    }

    /**
     * A contact who reaches ONE space gets that space's brand, slug or no slug.
     *
     * They are deliberately never asked to choose (see `spaces()`), so they live on the spaceless
     * `/client/*` routes for their whole visit. Answering with the tenant's brand there meant a
     * portal that greeted the client by name on its home page and called itself «CampaignsHub» on
     * every other one — and a white-labelled agency's colours silently replacing the client's.
     */
    public function test_a_contact_with_one_space_gets_that_spaces_brand_without_selecting_it(): void
    {
        $alpha = $this->client('Alpha');
        $this->contactIn($alpha, 'solo@alpha.test');

        $branding = app(BrandingService::class);
        $branding->saveSettings('tenant', null, ['colors' => ['primary' => '#333333']]);
        $branding->saveSettings('client', (string) $alpha->id, ['colors' => ['primary' => '#AA0000']]);

        $token = $this->portalLogin('solo@alpha.test');

        $data = $this->withHeaders($this->auth($token))->getJson('/api/v1/client/branding')
            ->assertOk()->json('data');

        $this->assertSame('#AA0000', $data['colors']['primary']);
        $this->assertSame($alpha->slug, $data['space']['slug']);
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
