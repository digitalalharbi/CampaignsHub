<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Jobs\SyncMeasurementPropertyJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PendingProjectLink;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * INTEG-PROJECT-LINK-STORES-GA4-001 (Owner 2026-11-06) — a store (سلة / زد) and a GA4 property are linked the
 * way an ad account is: from the project, through the platform's own page FOR that project, and back to it.
 *
 *     project → «ربط» → platform → its sign-in / authorise page → the store or the property → «تم» → the project
 *
 * Held here, per provider:
 *
 *  - the sign-in opens that project's OWN connection and stores NOTHING — no store, no property, no binding;
 *  - what the journey may choose from is asked of the platform and served to that project only;
 *  - the one confirmed source is the only row written, and it is bound to that project alone;
 *  - a state minted for another project or by another person decides nothing; a cancel leaves nothing;
 *  - a second confirm is the same link; a source another project reads is refused, never shared;
 *  - a store that does not state its currency has none — its money is withheld, never assumed.
 */
final class ProjectLinkStoresAndGa4Test extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = ['salla' => 'store-9', 'zid' => 'zid-7', 'ga4' => '111'];

    private const TYPE = ['salla' => 'store', 'zid' => 'store', 'ga4' => Ga4PropertyDiscovery::ACCOUNT_TYPE];

    private const PURPOSE = ['salla' => 'ecommerce', 'zid' => 'ecommerce', 'ga4' => 'analytics'];

    private Tenant $tenant;

    private User $owner;

    private Project $a;

    private Project $b;

    /** What the store platform says its currency is; null = it does not say. */
    private ?string $storeCurrency = 'SAR';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->owner = $this->member($this->tenant, Permission::pluck('key')->all(), 'Owner One');
        $this->a = $this->project($this->tenant, 'Project A');
        $this->b = $this->project($this->tenant, 'Project B');

        foreach (['salla', 'zid'] as $store) {
            foreach (['client_id', 'client_secret'] as $key) {
                config()->set("commerce_platforms.platforms.{$store}.{$key}", "test-{$key}");
            }
        }
        config()->set('measurement_platforms.platforms.ga4.client_id', 'test-client-id');
        config()->set('measurement_platforms.platforms.ga4.client_secret', 'test-client-secret');

        Queue::fake();
        $this->fakePlatforms();
    }

    /** @return array<string, array{0:string}> */
    public static function providers(): array
    {
        return ['salla' => ['salla'], 'zid' => ['zid'], 'ga4' => ['ga4']];
    }

    // ── the sign-in ──────────────────────────────────────────────────────────────────────────────

    #[DataProvider('providers')]
    public function test_the_return_lands_on_the_project_and_the_sign_in_stores_nothing(string $provider): void
    {
        $state = $this->start($provider, ['project_id' => $this->a->id, 'return_portal' => 'agency']);
        $location = (string) $this->get($this->returnUrl($provider, "code=abc&state={$state}"))->headers->get('Location');

        $connection = ProviderConnection::withoutGlobalScopes()->firstOrFail();
        $this->assertStringContainsString("/agency/projects/{$this->a->id}/integrations?", $location);
        $this->assertStringContainsString('outcome=connected', $location);
        $this->assertStringContainsString('link='.$connection->id, $location);
        // The project is a place to land, never a parameter that decides anything.
        $this->assertStringNotContainsString('project=', $location);

        $this->assertSame((string) $this->a->id, (string) $connection->project_id, 'the sign-in did not open this project\'s own connection');
        $this->assertTrue(PendingProjectLink::holds((string) $connection->id, $this->tenant->id, $this->a->id, (int) $this->owner->id));

        // Nothing stored, nothing bound, nothing queued — the platform was not even asked what it holds.
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();

        $link = $this->link($this->a, $connection)->assertOk()->json('data');
        $this->assertTrue($link['pending']);
        $this->assertSame($provider === 'ga4' ? 'measurement' : 'commerce', $link['connection']['kind']);
        $this->assertSame([], $link['accounts']);
    }

    #[DataProvider('providers')]
    public function test_a_refusal_at_the_platform_returns_to_the_project_with_nothing_opened(string $provider): void
    {
        $state = $this->start($provider, ['project_id' => $this->a->id]);

        $this->get($this->returnUrl($provider, "error=access_denied&state={$state}"))
            ->assertRedirectContains("/app/projects/{$this->a->id}/integrations?")
            ->assertRedirectContains('outcome=denied');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());

        // The state was spent by the refusal: replaying it with a code opens nothing either.
        $this->get($this->returnUrl($provider, "code=abc&state={$state}"))->assertRedirectContains('outcome=invalid_state');
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
    }

    #[DataProvider('providers')]
    public function test_starting_for_a_project_needs_the_right_to_link_to_it(string $provider): void
    {
        // Another company's project is not a project at all here.
        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rival-'.uniqid(), 'status' => 'active']);
        $theirs = $this->project($rival, 'Rival Project');
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->startPath($provider), ['project_id' => $theirs->id])
            ->assertStatus(422);

        // A member who may connect platforms but is not on this project may not open a journey for it.
        $outsider = $this->member($this->tenant, ['integrations.connect', 'integrations.view'], 'Outsider');
        $this->actingAs($outsider, 'sanctum')
            ->postJson($this->startPath($provider), ['project_id' => $this->a->id])
            ->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->startPath($provider), ['project_id' => $this->a->id, 'return_portal' => 'admin'])
            ->assertStatus(422);
    }

    #[DataProvider('providers')]
    public function test_a_project_deleted_while_the_person_was_away_is_refused_before_the_code_is_exchanged(string $provider): void
    {
        $state = $this->start($provider, ['project_id' => $this->a->id]);
        $this->a->delete();

        $location = (string) $this->get($this->returnUrl($provider, "code=abc&state={$state}"))->headers->get('Location');

        $this->assertStringContainsString('outcome=project_refused', $location);
        $this->assertStringNotContainsString((string) $this->a->id, $location, 'a refused journey named the project');
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_a_person_who_lost_the_right_while_away_is_refused_on_the_callback(string $provider): void
    {
        $state = $this->start($provider, ['project_id' => $this->a->id]);
        $this->owner->roles()->detach();

        $this->get($this->returnUrl($provider, "code=abc&state={$state}"))->assertRedirectContains('outcome=project_refused');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    // ── the step after it ────────────────────────────────────────────────────────────────────────

    #[DataProvider('providers')]
    public function test_what_the_sign_in_reaches_is_asked_and_not_stored_and_only_the_confirmed_one_is_written(string $provider): void
    {
        $connection = $this->signIn($provider, $this->a);

        $listed = $this->sources($this->a, $connection)->assertOk()->json('data');
        $this->assertSame($provider === 'ga4' ? 'measurement' : 'commerce', $listed['kind']);
        $this->assertContains(self::SOURCE[$provider], array_column($listed['sources'], 'external_id'));
        $this->assertSame($provider === 'ga4' ? 3 : 1, $listed['total']);
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count(), 'listing wrote a row');

        $this->confirm($this->a, $connection, self::SOURCE[$provider])->assertCreated();

        $row = ExternalAccount::withoutGlobalScopes()->sole();
        $this->assertSame(self::SOURCE[$provider], $row->external_id);
        $this->assertSame(self::TYPE[$provider], $row->account_type);
        $this->assertSame((string) $connection->id, (string) $row->provider_connection_id);

        $binding = ProjectIntegrationBinding::withoutGlobalScopes()->sole();
        $this->assertSame((string) $this->a->id, (string) $binding->project_id);
        $this->assertSame(self::PURPOSE[$provider], $binding->purpose);
        $this->assertTrue((bool) $binding->is_active);

        // The first read is queued for the confirmed source and for nothing else.
        $provider === 'ga4'
            ? Queue::assertPushed(SyncMeasurementPropertyJob::class, 1)
            : Queue::assertPushed(SyncStoreJob::class, 1);

        // The journey is over: nothing is left pending, and the project's own summary names what it linked.
        $link = $this->link($this->a, $connection)->assertOk()->json('data');
        $this->assertFalse($link['pending']);
        $this->assertSame([self::SOURCE[$provider]], array_column($link['accounts'], 'external_id'));
    }

    public function test_ga4_properties_are_searched_on_the_server_by_name_id_or_analytics_account(): void
    {
        $connection = $this->signIn('ga4', $this->a);

        $byName = $this->sources($this->a, $connection, 'q=nova')->assertOk()->json('data');
        $this->assertSame(['222'], array_column($byName['sources'], 'external_id'));
        $this->assertSame('Nova Group', $byName['sources'][0]['detail']);

        $this->assertSame(['333'], array_column($this->sources($this->a, $connection, 'q=333')->json('data.sources'), 'external_id'));
        // Ordered by Analytics account, then by name: «Acme Blog» before «Acme Store».
        $this->assertSame(['333', '111'], array_column($this->sources($this->a, $connection, 'q=acme+group')->json('data.sources'), 'external_id'));
        $this->assertSame([], $this->sources($this->a, $connection, 'q=nothing-like-this')->json('data.sources'));
    }

    #[DataProvider('providers')]
    public function test_a_source_the_platform_did_not_list_for_this_sign_in_is_never_stored(string $provider): void
    {
        $connection = $this->signIn($provider, $this->a);

        $this->confirm($this->a, $connection, 'not-listed-999')->assertStatus(422);

        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    #[DataProvider('providers')]
    public function test_confirming_twice_is_one_link(string $provider): void
    {
        $connection = $this->signIn($provider, $this->a);

        $first = $this->confirm($this->a, $connection, self::SOURCE[$provider])->assertCreated()->json('data.account_id');
        $second = $this->confirm($this->a, $connection, self::SOURCE[$provider])->assertOk()->json('data.account_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->count());
        // The second press is not a reason to read the source again.
        $provider === 'ga4'
            ? Queue::assertPushed(SyncMeasurementPropertyJob::class, 1)
            : Queue::assertPushed(SyncStoreJob::class, 1);
    }

    // ── nobody else's ────────────────────────────────────────────────────────────────────────────

    #[DataProvider('providers')]
    public function test_the_journey_serves_only_the_project_and_person_it_was_opened_for(string $provider): void
    {
        $connection = $this->signIn($provider, $this->a);

        // Project B, same tenant, same person: the connection does not exist for it.
        $this->sources($this->b, $connection)->assertNotFound();
        $this->confirm($this->b, $connection, self::SOURCE[$provider])->assertNotFound();
        $this->assertFalse($this->link($this->b, $connection)->assertOk()->json('data.pending'), 'project B resumed project A\'s journey');

        // Another person on project A: the journey is not theirs to resume.
        $colleague = $this->member($this->tenant, Permission::pluck('key')->all(), 'Colleague');
        $this->assertFalse($this->link($this->a, $connection, $colleague)->assertOk()->json('data.pending'));

        // A member without the right to connect cannot list or confirm.
        $reader = $this->member($this->tenant, ['integrations.view', 'projects.view'], 'Reader');
        $this->sources($this->a, $connection, '', $reader)->assertForbidden();
        $this->confirm($this->a, $connection, self::SOURCE[$provider], $reader)->assertForbidden();

        // Another tenant: 404 by every route, and nothing of ours in the answer.
        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rival-'.uniqid(), 'status' => 'active']);
        $rivalOwner = $this->member($rival, Permission::pluck('key')->all(), 'Rival Owner');
        $theirs = $this->project($rival, 'Rival Project');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        foreach (['', '/sources'] as $suffix) {
            $this->actingAs($rivalOwner, 'sanctum')->withHeader('X-Tenant-ID', $rival->id)->withHeader('X-Project-Id', $theirs->id)
                ->getJson("/api/v1/projects/{$theirs->id}/integrations/link/{$connection->id}{$suffix}")
                ->assertNotFound();
        }

        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());
    }

    #[DataProvider('providers')]
    public function test_a_source_another_project_reads_is_refused_never_shared(string $provider): void
    {
        $forA = $this->signIn($provider, $this->a);
        $this->confirm($this->a, $forA, self::SOURCE[$provider])->assertCreated();

        // Project B signs in to the same platform with its own journey and reaches the same real source.
        $forB = $this->signIn($provider, $this->b);
        $this->assertNotSame((string) $forA->id, (string) $forB->id, 'two projects shared one connection');

        $row = collect($this->sources($this->b, $forB)->assertOk()->json('data.sources'))->firstWhere('external_id', self::SOURCE[$provider]);
        $this->assertSame('elsewhere', $row['linked']);
        $this->assertStringNotContainsString('Project A', $this->sources($this->b, $forB)->getContent(), 'the other project was named');

        $refusal = $this->confirm($this->b, $forB, self::SOURCE[$provider])->assertStatus(409);
        $this->assertStringNotContainsString('Project A', $refusal->getContent());

        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->count(), 'a refused link left a row behind');
        $binding = ProjectIntegrationBinding::withoutGlobalScopes()->sole();
        $this->assertSame((string) $this->a->id, (string) $binding->project_id);

        // And A's own journey reads its source as its own.
        $forAAgain = $this->signIn($provider, $this->a);
        $mine = collect($this->sources($this->a, $forAAgain)->json('data.sources'))->firstWhere('external_id', self::SOURCE[$provider]);
        $this->assertSame('here', $mine['linked']);
    }

    #[DataProvider('providers')]
    public function test_the_older_endpoints_cannot_carry_a_journey_source_to_another_project(string $provider): void
    {
        $connection = $this->signIn($provider, $this->a);
        $id = (string) $this->confirm($this->a, $connection, self::SOURCE[$provider])->assertCreated()->json('data.account_id');

        // The store's «project» setter and the generic binding endpoints are both fenced by the connection's project.
        if ($provider !== 'ga4') {
            $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/commerce/stores/{$id}", ['project_id' => $this->b->id])->assertForbidden();
        }
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $this->b->id)
            ->postJson("/api/v1/projects/{$this->b->id}/integrations/bindings", ['external_account_id' => $id, 'purpose' => self::PURPOSE[$provider]])
            ->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $this->b->id)
            ->postJson("/api/v1/projects/{$this->b->id}/integrations/bindings/batch", [
                'connection_id' => $connection->id, 'external_account_ids' => [$id], 'purpose' => self::PURPOSE[$provider],
            ])
            ->assertForbidden();

        $binding = ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->sole();
        $this->assertSame((string) $this->a->id, (string) $binding->project_id);
    }

    #[DataProvider('providers')]
    public function test_a_cancelled_journey_leaves_nothing(string $provider): void
    {
        $connection = $this->signIn($provider, $this->a);
        $this->sources($this->a, $connection)->assertOk();

        // «إلغاء» — the same request the picker sends.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/connections/{$connection->id}/remove", ['unconfirmed_only' => true])
            ->assertOk()
            ->assertJsonPath('data.removed', true);

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->whereNull('removed_at')->count());
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
        $this->sources($this->a, $connection)->assertNotFound();
        $this->confirm($this->a, $connection, self::SOURCE[$provider])->assertNotFound();
    }

    // ── what the project's space lists ───────────────────────────────────────────────────────────

    #[DataProvider('providers')]
    public function test_the_projects_space_lists_its_own_source_and_project_b_and_tenant_b_see_nothing(string $provider): void
    {
        $connection = $this->signIn($provider, $this->a);
        $id = (string) $this->confirm($this->a, $connection, self::SOURCE[$provider])->assertCreated()->json('data.account_id');

        $path = $provider === 'ga4' ? '/api/v1/measurement/properties' : '/api/v1/commerce/stores';
        $rows = static fn (TestResponse $r): array => collect($r->json('data'))->firstWhere('key', $provider)[$provider === 'ga4' ? 'properties' : 'stores'];

        $mine = $rows($this->actingAs($this->owner, 'sanctum')->getJson($path.'?project_id='.$this->a->id)->assertOk());
        $this->assertSame([$id], array_column($mine, 'id'));
        $this->assertSame((string) $this->a->id, (string) $mine[0]['project_id']);

        $others = $this->actingAs($this->owner, 'sanctum')->getJson($path.'?project_id='.$this->b->id)->assertOk();
        $this->assertSame([], $rows($others));
        $this->assertStringNotContainsString(self::SOURCE[$provider], $others->getContent());

        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rival-'.uniqid(), 'status' => 'active']);
        $rivalOwner = $this->member($rival, Permission::pluck('key')->all(), 'Rival Owner');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $abroad = $this->actingAs($rivalOwner, 'sanctum')->withHeader('X-Tenant-ID', $rival->id)->getJson($path)->assertOk();
        $this->assertStringNotContainsString(self::SOURCE[$provider], $abroad->getContent());
        $this->actingAs($rivalOwner, 'sanctum')->withHeader('X-Tenant-ID', $rival->id)->getJson($path.'?project_id='.$this->a->id)->assertNotFound();
    }

    public function test_the_projects_space_no_longer_lists_properties_nobody_chose(): void
    {
        // An older, workspace-wide sign-in catalogued two properties; project A reads one of them.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $legacy = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'ga4',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Google Analytics 4',
        );
        $chosen = $this->legacyRow($legacy, Ga4PropertyDiscovery::ACCOUNT_TYPE, '111', 'Acme Store');
        $this->legacyRow($legacy, Ga4PropertyDiscovery::ACCOUNT_TYPE, '222', 'Nova Shop');
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->a->id, 'client_workspace_id' => $this->a->client_workspace_id,
            'external_account_id' => $chosen->id, 'provider' => 'ga4', 'purpose' => 'analytics', 'is_active' => true,
        ]);

        $ga4 = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/measurement/properties?project_id='.$this->a->id)->assertOk()->json('data'))->firstWhere('key', 'ga4');

        $this->assertSame(['111'], array_column($ga4['properties'], 'property_id'), 'the existing bound property stopped being listed, or an unchosen one still is');
        $this->assertSame((string) $chosen->id, $ga4['properties'][0]['id'], 'the bound property changed its id');
        $this->assertSame(1, $ga4['selected_count']);
        $this->assertSame(1, $ga4['discovered_count']);
    }

    // ── an existing link keeps its id ────────────────────────────────────────────────────────────

    #[DataProvider('providers')]
    public function test_relinking_a_source_this_project_already_reads_keeps_its_row_and_its_id(string $provider): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $legacy = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT-old', 'RT', Carbon::now()->addDays(30), raw: ['authorization' => 'MGR']), connectionName: 'Legacy',
        );
        $existing = $this->legacyRow($legacy, self::TYPE[$provider], self::SOURCE[$provider], 'Existing');
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->a->id, 'client_workspace_id' => $this->a->client_workspace_id,
            'external_account_id' => $existing->id, 'provider' => $provider, 'purpose' => self::PURPOSE[$provider], 'is_active' => true,
        ]);

        $connection = $this->signIn($provider, $this->a);
        $out = $this->confirm($this->a, $connection, self::SOURCE[$provider])->assertOk()->json('data');

        $this->assertTrue($out['relinked']);
        $this->assertSame((string) $existing->id, $out['account_id'], 'the source was given a second row: its history would split');
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('status', '!=', 'superseded')->count());
        $this->assertSame((string) $connection->id, (string) $existing->refresh()->provider_connection_id);
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());
    }

    // ── money ────────────────────────────────────────────────────────────────────────────────────

    #[DataProvider('stores')]
    public function test_a_store_that_does_not_state_its_currency_has_none_and_its_money_is_withheld(string $provider): void
    {
        $this->storeCurrency = null;
        $connection = $this->signIn($provider, $this->a);

        $this->assertNull($this->sources($this->a, $connection)->assertOk()->json('data.sources.0.currency'));
        $this->confirm($this->a, $connection, self::SOURCE[$provider])->assertCreated();

        $this->assertNull(ExternalAccount::withoutGlobalScopes()->sole()->currency, 'a currency was assumed for a store that stated none');
        $account = $this->link($this->a, $connection)->assertOk()->json('data.accounts.0');
        $this->assertNull($account['currency']);
        $this->assertSame('withheld_unknown', $account['money']['state']);
    }

    #[DataProvider('stores')]
    public function test_a_store_keeps_the_currency_it_states(string $provider): void
    {
        $connection = $this->signIn($provider, $this->a);
        $this->confirm($this->a, $connection, self::SOURCE[$provider])->assertCreated();

        $this->assertSame('SAR', ExternalAccount::withoutGlobalScopes()->sole()->currency);
    }

    /** @return array<string, array{0:string}> */
    public static function stores(): array
    {
        return ['salla' => ['salla'], 'zid' => ['zid']];
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────

    private function signIn(string $provider, Project $project): ProviderConnection
    {
        $state = $this->start($provider, ['project_id' => $project->id]);
        $location = (string) $this->get($this->returnUrl($provider, "code=abc&state={$state}"))->headers->get('Location');
        $this->assertStringContainsString('outcome=connected', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return ProviderConnection::withoutGlobalScopes()->findOrFail((string) $query['link']);
    }

    private function startPath(string $provider): string
    {
        return $provider === 'ga4'
            ? '/api/v1/integrations/measurement/ga4/oauth/start'
            : "/api/v1/integrations/commerce/{$provider}/oauth/start";
    }

    private function returnUrl(string $provider, string $query): string
    {
        return ($provider === 'ga4' ? '/api/v1/oauth/measurement/ga4/callback' : "/api/v1/oauth/commerce/{$provider}/callback").'?'.$query;
    }

    /** @param  array<string,mixed>  $body */
    private function start(string $provider, array $body): string
    {
        $url = $this->actingAs($this->owner, 'sanctum')->postJson($this->startPath($provider), $body)->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function link(Project $project, ProviderConnection $connection, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->getJson("/api/v1/projects/{$project->id}/integrations/link/{$connection->id}");
    }

    private function sources(Project $project, ProviderConnection $connection, string $query = '', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->getJson("/api/v1/projects/{$project->id}/integrations/link/{$connection->id}/sources".($query === '' ? '' : '?'.$query));
    }

    private function confirm(Project $project, ProviderConnection $connection, string $externalId, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->postJson("/api/v1/projects/{$project->id}/integrations/link/{$connection->id}/sources", ['external_id' => $externalId]);
    }

    private function fakePlatforms(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'accounts.salla.sa/oauth2/token') => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 1209600]),
                str_contains($url, '/store/info') => Http::response(['data' => array_filter([
                    'id' => 'store-9', 'name' => 'متجر تجريبي', 'domain' => 'demo.salla.sa', 'currency' => $this->storeCurrency,
                ], static fn ($v) => $v !== null)]),
                str_contains($url, 'oauth.zid.sa/oauth/token') => Http::response(['access_token' => 'AT', 'authorization' => 'MGR', 'refresh_token' => 'RT', 'expires_in' => 3600]),
                str_contains($url, 'managers/account/profile') => Http::response(['user' => ['store' => array_filter([
                    'id' => 'zid-7', 'title' => 'متجر زد', 'url' => 'https://shop.zid.store', 'currency' => $this->storeCurrency,
                ], static fn ($v) => $v !== null)]]),
                str_contains($url, 'oauth2.googleapis.com') => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]),
                str_contains($url, 'analyticsadmin.googleapis.com') => Http::response(['accountSummaries' => [
                    ['account' => 'accounts/100', 'displayName' => 'Acme Group', 'propertySummaries' => [
                        ['property' => 'properties/111', 'displayName' => 'Acme Store'],
                        ['property' => 'properties/333', 'displayName' => 'Acme Blog'],
                    ]],
                    ['account' => 'accounts/200', 'displayName' => 'Nova Group', 'propertySummaries' => [
                        ['property' => 'properties/222', 'displayName' => 'Nova Shop'],
                    ]],
                ]]),
                default => Http::response([], 404),
            };
        });
    }

    private function legacyRow(ProviderConnection $connection, string $type, string $externalId, string $name): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $connection->tenant_id, 'provider_connection_id' => $connection->getKey(), 'provider' => $connection->provider,
            'account_type' => $type, 'external_id' => $externalId, 'name' => $name, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function member(Tenant $tenant, array $permissions, string $name): User
    {
        $user = User::create(['name' => $name, 'email' => 'u-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => $name, 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        return $user;
    }

    private function project(Tenant $tenant, string $name): Project
    {
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }
}
