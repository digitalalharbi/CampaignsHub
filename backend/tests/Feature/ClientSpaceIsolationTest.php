<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Services\BillingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isolated client spaces (PORTAL-CLIENT-001).
 *
 * One person is routinely named on more than one of an agency's clients — a marketing lead covering
 * two brands, an owner of two companies. Before this, the portal merged those into one view, so the
 * two brands' invoices, messages and campaigns sat in the same list with nothing to tell them apart.
 *
 * A space is therefore selected explicitly, and everything narrows to it. The tests below hold two
 * lines: the merge does not happen inside a chosen space, and a space that is not the contact's own
 * cannot be entered by naming its slug.
 */
final class ClientSpaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create([
            'name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true,
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    private function space(string $name, string $slug): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => $slug,
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function requestIn(ClientWorkspace $space, string $email, string $reference): ExternalRequest
    {
        return ExternalRequest::create([
            'tenant_id' => $this->tenant->id,
            'reference' => $reference,
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Client',
            'contact_email' => $email,
            'contact_phone' => '+966500000009',
            'client_id' => $space->id,
            'submitted_at' => now(),
        ]);
    }

    private function login(string $email): string
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

    /** The whole point: two brands, one person, two separate spaces rather than one merged list. */
    public function test_a_contact_on_two_clients_gets_two_spaces_not_one_merged_view(): void
    {
        $alpha = $this->space('Alpha Brand', 'alpha-brand');
        $beta = $this->space('Beta Brand', 'beta-brand');
        $this->requestIn($alpha, 'lead@both.test', 'REQ-ALPHA-1');
        $this->requestIn($beta, 'lead@both.test', 'REQ-BETA-1');

        $token = $this->login('lead@both.test');

        $spaces = $this->withHeaders($this->auth($token))->getJson('/api/v1/client/spaces')
            ->assertOk()->json('data.spaces');
        $this->assertCount(2, $spaces);

        // Inside Alpha, only Alpha's request. Beta's is not merged in.
        $inAlpha = $this->withHeaders($this->auth($token, 'alpha-brand'))->getJson('/api/v1/client/requests')
            ->assertOk()->json('data.requests');
        $this->assertSame(['REQ-ALPHA-1'], array_column($inAlpha, 'reference'));

        $inBeta = $this->withHeaders($this->auth($token, 'beta-brand'))->getJson('/api/v1/client/requests')
            ->assertOk()->json('data.requests');
        $this->assertSame(['REQ-BETA-1'], array_column($inBeta, 'reference'));
    }

    /** Naming another client's slug does not open it — and 404 tells a prober nothing. */
    public function test_a_slug_the_contact_does_not_own_is_not_found(): void
    {
        $mine = $this->space('Mine', 'mine-space');
        $theirs = $this->space('Theirs', 'theirs-space');
        $this->requestIn($mine, 'me@x.test', 'REQ-MINE-1');
        $this->requestIn($theirs, 'other@x.test', 'REQ-THEIRS-1');

        $token = $this->login('me@x.test');

        $this->withHeaders($this->auth($token, 'theirs-space'))->getJson('/api/v1/client/requests')
            ->assertNotFound();
        $this->withHeaders($this->auth($token, 'theirs-space'))->getJson('/api/v1/client/invoices')
            ->assertNotFound();
    }

    /** A slug that matches nothing must not fall back to the unfiltered view. */
    public function test_an_unknown_slug_is_refused_rather_than_ignored(): void
    {
        $mine = $this->space('Mine', 'mine-space-2');
        $this->requestIn($mine, 'me2@x.test', 'REQ-MINE-2');

        $token = $this->login('me2@x.test');

        $this->withHeaders($this->auth($token, 'no-such-space'))->getJson('/api/v1/client/requests')
            ->assertNotFound();
    }

    /** Billing narrows with the space — one brand's invoices never appear inside the other's. */
    public function test_billing_is_confined_to_the_selected_space(): void
    {
        $alpha = $this->space('Alpha', 'alpha-b');
        $beta = $this->space('Beta', 'beta-b');
        $this->requestIn($alpha, 'lead2@both.test', 'REQ-A-2');
        $this->requestIn($beta, 'lead2@both.test', 'REQ-B-2');

        $billing = app(BillingService::class);
        $billing->createQuote([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $alpha->id,
            'subtotal' => 1000, 'total' => 1000, 'status' => 'sent',
        ]);
        $billing->createQuote([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $beta->id,
            'subtotal' => 2000, 'total' => 2000, 'status' => 'sent',
        ]);

        $token = $this->login('lead2@both.test');

        $alphaQuotes = $this->withHeaders($this->auth($token, 'alpha-b'))->getJson('/api/v1/client/quotes')
            ->assertOk()->json('data.quotes');
        $this->assertCount(1, $alphaQuotes);
        $this->assertSame('1000.00', $alphaQuotes[0]['total']);

        $betaQuotes = $this->withHeaders($this->auth($token, 'beta-b'))->getJson('/api/v1/client/quotes')
            ->assertOk()->json('data.quotes');
        $this->assertCount(1, $betaQuotes);
        $this->assertSame('2000.00', $betaQuotes[0]['total']);
    }

    /**
     * A request that belongs to the contact but to a DIFFERENT space is not reachable by reference
     * from inside this one — otherwise the space would isolate the list but not the detail page.
     */
    public function test_a_request_from_another_space_is_not_reachable_by_reference(): void
    {
        $alpha = $this->space('Alpha', 'alpha-c');
        $beta = $this->space('Beta', 'beta-c');
        $this->requestIn($alpha, 'lead3@both.test', 'REQ-A-3');
        $this->requestIn($beta, 'lead3@both.test', 'REQ-B-3');

        $token = $this->login('lead3@both.test');

        $this->withHeaders($this->auth($token, 'alpha-c'))->getJson('/api/v1/client/requests/REQ-A-3')->assertOk();
        $this->withHeaders($this->auth($token, 'alpha-c'))->getJson('/api/v1/client/requests/REQ-B-3')->assertNotFound();
    }

    /** With no space named, behaviour is unchanged — the existing `/client/*` session keeps working. */
    public function test_omitting_the_space_preserves_the_existing_behaviour(): void
    {
        $alpha = $this->space('Alpha', 'alpha-d');
        $beta = $this->space('Beta', 'beta-d');
        $this->requestIn($alpha, 'lead4@both.test', 'REQ-A-4');
        $this->requestIn($beta, 'lead4@both.test', 'REQ-B-4');

        $token = $this->login('lead4@both.test');

        $all = $this->withHeaders($this->auth($token))->getJson('/api/v1/client/requests')
            ->assertOk()->json('data.requests');
        $this->assertCount(2, $all);
    }

    /** A contact with one client still gets a space, so the URL can always name where they are. */
    public function test_a_single_client_contact_still_gets_a_named_space(): void
    {
        $only = $this->space('Only Client', 'only-client');
        $this->requestIn($only, 'solo@x.test', 'REQ-SOLO-1');

        $token = $this->login('solo@x.test');

        $spaces = $this->withHeaders($this->auth($token))->getJson('/api/v1/client/spaces')
            ->assertOk()->json('data.spaces');

        $this->assertCount(1, $spaces);
        $this->assertSame('only-client', $spaces[0]['slug']);
        $this->assertSame('Only Client', $spaces[0]['name']);
    }

    public function test_the_spaces_list_needs_a_session(): void
    {
        $this->getJson('/api/v1/client/spaces')->assertUnauthorized();
    }

    /**
     * The bug this exists for: `ClientWorkspace` carries the tenant global scope, and the slug lookup
     * ran before any endpoint had bound a tenant — so a space the contact genuinely owns came back
     * 404 in a real browser while every test passed, because `setUp` had bound a tenant for the whole
     * case. Forgetting it here reproduces what a request actually starts from.
     */
    public function test_a_space_resolves_without_a_tenant_already_bound(): void
    {
        $space = $this->space('Unbound', 'unbound-space');
        $this->requestIn($space, 'unbound@x.test', 'REQ-UNBOUND-1');

        $token = $this->login('unbound@x.test');

        app(TenantContext::class)->forget();

        $this->withHeaders($this->auth($token, 'unbound-space'))->getJson('/api/v1/client/requests')
            ->assertOk()
            ->assertJsonPath('data.requests.0.reference', 'REQ-UNBOUND-1');
    }
}
