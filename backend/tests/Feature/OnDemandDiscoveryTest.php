<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetricDefinitionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * INTEG-ON-DEMAND-DISCOVERY-001 (Owner 2026-11-06) — «الاكتشاف عند الطلب».
 *
 * Production held ~2450 discovered ad accounts against 31 linked: every sign-in walked everything the
 * identity could reach and stored all of it. A project's journey now asks for the organisations (one call),
 * then the CHOSEN organisation's accounts, and stores the ONE account that is confirmed.
 *
 * Proven here with the provider's request log and the database, not with the journey's own answers: an
 * organisation with 30 accounts next to a second organisation that is never opened; after one account is
 * linked there is exactly one stored row and one binding for the connection, and the sweeps that run
 * afterwards ask the provider about that account only.
 */
final class OnDemandDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const LINKED = 'acct-07';

    private Tenant $tenant;

    private User $owner;

    private Project $projectA;

    private Project $projectB;

    /** @var list<string> every URL asked of the provider, in order */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->owner = $this->member(Permission::pluck('key')->all());

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->projectA = $this->project($workspace, 'Project A');
        $this->projectB = $this->project($workspace, 'Project B');

        foreach (PlatformCredentials::for('snapchat')->requires() as $key) {
            config()->set("ad_platforms.platforms.snapchat.{$key}", "test-{$key}");
        }

        $this->fakeSnapchat();
    }

    public function test_signing_in_catalogues_nothing_and_asks_for_no_organisation_or_account(): void
    {
        Queue::fake();
        $connection = $this->signIn($this->projectA);

        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count(), 'the sign-in stored accounts nobody chose');
        $this->assertSame([], $this->askedAbout('organizations'));
        $this->assertSame([], $this->askedAbout('adaccounts'));
        $this->assertSame((string) $this->projectA->id, (string) $connection->project_id);
    }

    public function test_organisations_are_one_call_and_the_chosen_organisations_accounts_are_the_only_ones_read(): void
    {
        Queue::fake();
        $connection = $this->signIn($this->projectA);
        $this->asked = [];

        $organisations = $this->directory($this->projectA, $connection, 'organisations')->assertOk()->json('data.organisations');
        $this->assertSame([['id' => 'org-1', 'name' => 'Acme Group'], ['id' => 'org-2', 'name' => 'Nova Group']], $organisations);
        $this->assertCount(1, $this->asked, 'listing organisations cost more than one provider call');
        $this->assertStringContainsString('/me/organizations', $this->asked[0]);
        $this->assertStringNotContainsString('with_ad_accounts', $this->asked[0], 'the organisations call pulled every account with it');

        // Asked again: answered from the journey's own short-lived listing, not the provider.
        $this->directory($this->projectA, $connection, 'organisations')->assertOk();
        $this->assertCount(1, $this->asked);

        $page = $this->directory($this->projectA, $connection, 'accounts?organisation=org-1')->assertOk()->json('data');
        $this->assertSame(30, $page['total']);
        $this->assertCount(25, $page['accounts']);
        $this->assertSame('Account 01', $page['accounts'][0]['name']);
        $this->assertSame('SAR', $page['accounts'][0]['currency']);
        $this->assertCount(1, $this->askedAbout('/organizations/org-1/adaccounts'));
        $this->assertSame([], $this->askedAbout('org-2'), 'an organisation nobody chose was read');

        // Paging and searching are the server's work on that one answer.
        $second = $this->directory($this->projectA, $connection, 'accounts?organisation=org-1&page=2')->assertOk()->json('data');
        $this->assertCount(5, $second['accounts']);
        $found = $this->directory($this->projectA, $connection, 'accounts?organisation=org-1&q=count+07')->assertOk()->json('data');
        $this->assertSame([self::LINKED], array_column($found['accounts'], 'external_id'));
        $this->assertSame(1, $found['total']);
        $this->assertCount(2, $this->asked, 'paging or searching asked the provider again');

        // Nothing has been stored by looking.
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());
    }

    public function test_linking_one_account_of_thirty_stores_and_binds_exactly_one(): void
    {
        Queue::fake();
        $connection = $this->signIn($this->projectA);

        $this->confirm($this->projectA, $connection, self::LINKED, 'org-1')->assertCreated();

        $stored = ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $connection->id)->get();
        $this->assertCount(1, $stored, 'more than the confirmed account was stored');
        $this->assertSame(self::LINKED, (string) $stored[0]->external_id);
        $this->assertSame('Account 07', (string) $stored[0]->name);
        $this->assertSame('SAR', (string) $stored[0]->currency);
        $this->assertSame('org-1', (string) $stored[0]->parent_external_id);
        $this->assertSame('Acme Group', (string) $stored[0]->parent_name);

        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());
        $this->assertSame((string) $this->projectA->id, (string) ProjectIntegrationBinding::withoutGlobalScopes()->value('project_id'));

        // Confirming it again is the same decision: still one row, one binding.
        $this->confirm($this->projectA, $connection, self::LINKED, 'org-1')->assertOk();
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->count());

        // The listing now says so, and the project's own link summary shows the one account.
        $page = $this->directory($this->projectA, $connection, 'accounts?organisation=org-1&q=count+07')->json('data.accounts');
        $this->assertSame('here', $page[0]['linked']);
    }

    public function test_an_account_the_provider_did_not_list_under_that_organisation_is_never_stored(): void
    {
        Queue::fake();
        $connection = $this->signIn($this->projectA);

        $this->confirm($this->projectA, $connection, 'acct-of-someone-else', 'org-1')->assertStatus(422);
        // A real account id under an organisation the sign-in does not reach.
        $this->confirm($this->projectA, $connection, self::LINKED, 'org-9')->assertStatus(422);

        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    public function test_the_directory_serves_only_the_project_the_connection_was_opened_for(): void
    {
        Queue::fake();
        $connection = $this->signIn($this->projectA);
        $wide = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'snapchat', tokens: new OAuthTokens('WIDE', null, null), connectionName: 'Snapchat',
        );
        $this->asked = [];

        // Project B asking about project A's connection, and anybody asking about a workspace-wide one.
        $this->directory($this->projectB, $connection, 'organisations')->assertNotFound();
        $this->directory($this->projectB, $connection, 'accounts?organisation=org-1')->assertNotFound();
        $this->confirm($this->projectB, $connection, self::LINKED, 'org-1')->assertNotFound();
        $this->directory($this->projectA, $wide, 'organisations')->assertNotFound();

        $this->assertSame([], $this->asked, 'the provider was asked on behalf of a project that does not own the connection');
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());
    }

    public function test_an_account_feeding_another_project_is_refused_and_leaves_no_row_behind(): void
    {
        Queue::fake();
        $a = $this->signIn($this->projectA);
        $this->confirm($this->projectA, $a, self::LINKED, 'org-1')->assertCreated();

        $b = $this->signIn($this->projectB);
        $this->assertNotSame((string) $a->id, (string) $b->id);

        $listed = $this->directory($this->projectB, $b, 'accounts?organisation=org-1&q=count+07')->json('data.accounts');
        $this->assertSame('elsewhere', $listed[0]['linked']);

        $refused = $this->confirm($this->projectB, $b, self::LINKED, 'org-1')->assertStatus(409);
        $this->assertSame((string) $this->projectA->id, $refused->json('meta.assigned_project_id'));

        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $b->id)->count(), 'a refused link left a stored account behind');
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    /**
     * «إعادة الربط» for an account the project already reads through an older workspace-wide connection:
     * the SAME row moves onto the project's own connection — same id, same binding — and nothing else on
     * the older connection moves with it.
     */
    public function test_relinking_an_account_read_through_a_workspace_wide_connection_keeps_its_id_and_touches_no_other_project(): void
    {
        Queue::fake();
        $wide = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'snapchat', tokens: new OAuthTokens('WIDE', null, null), connectionName: 'Snapchat',
        );
        $mine = $this->stored($wide, self::LINKED, 'Old name');
        $theirs = $this->stored($wide, 'acct-99', 'Account of B');
        $unbound = $this->stored($wide, 'acct-55', 'Never linked');
        $this->bind($this->projectA, $wide, $mine);
        $this->bind($this->projectB, $wide, $theirs);
        $bindingOfA = (string) ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectA->id)->value('id');

        $own = $this->signIn($this->projectA);
        $result = $this->confirm($this->projectA, $own, self::LINKED, 'org-1')->assertOk()->json('data');

        $this->assertTrue($result['relinked']);
        $this->assertSame((string) $mine->id, $result['account_id'], 'the re-linked account changed its id — its history would be split');
        $mine->refresh();
        $this->assertSame((string) $own->id, (string) $mine->provider_connection_id);
        $this->assertSame('Account 07', (string) $mine->name);
        $this->assertSame($bindingOfA, (string) ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectA->id)->where('is_active', true)->value('id'));
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectA->id)->count());

        // The workspace-wide connection, project B's account on it, and the never-linked row are as they were.
        $this->assertSame((string) $wide->id, (string) $theirs->refresh()->provider_connection_id);
        $this->assertSame((string) $wide->id, (string) $unbound->refresh()->provider_connection_id);
        $this->assertTrue((bool) ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectB->id)->value('is_active'));
        $this->assertSame('connected', (string) $wide->refresh()->status);
        $this->assertSame('WIDE', app(TokenVault::class)->stored($wide)->accessToken);
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $own->id)->count());
    }

    public function test_the_sweeps_after_a_link_ask_the_provider_about_the_linked_account_only(): void
    {
        Queue::fake();
        $connection = $this->signIn($this->projectA);
        $this->confirm($this->projectA, $connection, self::LINKED, 'org-1')->assertCreated();
        $accountId = (string) ExternalAccount::withoutGlobalScopes()->value('id');
        $this->asked = [];

        // The discovery refresh — the button and the command share one service.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/connections/{$connection->id}/refresh")->assertSuccessful();
        $this->artisan('integrations:discovery-refresh', ['--connection' => (string) $connection->id, '--apply' => true]);
        $this->assertNotSame([], $this->asked, 'the refresh asked nothing, so it proved nothing');
        foreach ($this->asked as $url) {
            $this->assertStringContainsString('/adaccounts/'.self::LINKED, $url, 'the refresh asked about something other than the linked account');
        }
        $this->assertSame('Account 07 (renamed)', (string) ExternalAccount::withoutGlobalScopes()->value('name'));

        // The periodic metric and structure sweeps, and the token refresh.
        $this->asked = [];
        $this->artisan('integrations:sync')->assertSuccessful();
        $this->artisan('integrations:sync-structure')->assertSuccessful();
        $this->artisan('integrations:refresh-tokens')->assertSuccessful();

        $jobs = collect(Queue::pushedJobs())->flatten(1)->pluck('job');
        $this->assertNotSame(0, $jobs->count(), 'the sweeps queued nothing for the linked account');
        foreach ($jobs as $job) {
            $about = json_encode((array) $job);
            $this->assertStringContainsString($accountId, (string) $about, 'a sweep queued work for something other than the linked account');
            try {
                app()->call([$job, 'handle']);
            } catch (Throwable) {
                // The fake answers with empty bodies; what is being proven is WHAT was asked, not what came back.
            }
        }

        $this->assertNotSame([], $this->asked, 'the queued sweeps asked the provider nothing, so nothing was proven about what they ask');
        foreach ($this->asked as $url) {
            $this->assertTrue(
                str_contains($url, self::LINKED) || str_contains($url, 'access_token'),
                "a sweep asked the provider about something other than the linked account: {$url}",
            );
        }
        $this->assertSame([], $this->askedAbout('with_ad_accounts'));
        $this->assertSame([], $this->askedAbout('org-2'));

        // And after all of it: still one stored account, one binding.
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $connection->id)->count());
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function askedAbout(string $needle): array
    {
        return array_values(array_filter($this->asked, static fn (string $url): bool => str_contains($url, $needle)));
    }

    private function fakeSnapchat(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (str_contains($url, 'access_token')) {
                return Http::response(['access_token' => 'AT-'.uniqid(), 'refresh_token' => 'RT', 'expires_in' => 3600]);
            }

            $this->asked[] = $url;

            if (preg_match('#/me/organizations#', $url) === 1) {
                // The wide form, as the old discovery asked it: answered, so a regression shows up as stored rows.
                $wide = str_contains($url, 'with_ad_accounts');

                return Http::response(['organizations' => [
                    ['organization' => ['id' => 'org-1', 'name' => 'Acme Group'] + ($wide ? ['ad_accounts' => $this->accountsOf('org-1')] : [])],
                    ['organization' => ['id' => 'org-2', 'name' => 'Nova Group'] + ($wide ? ['ad_accounts' => $this->accountsOf('org-2')] : [])],
                ]]);
            }

            if (preg_match('#/organizations/(org-\d)/adaccounts#', $url, $m) === 1) {
                return Http::response(['adaccounts' => array_map(
                    static fn (array $a): array => ['sub_request_status' => 'SUCCESS', 'adaccount' => $a],
                    $this->accountsOf($m[1]),
                )]);
            }

            if (preg_match('#/adaccounts/('.self::LINKED.')$#', $url) === 1) {
                return Http::response(['adaccounts' => [['sub_request_status' => 'SUCCESS', 'adaccount' => [
                    'id' => self::LINKED, 'name' => 'Account 07 (renamed)', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'ACTIVE', 'organization_id' => 'org-1',
                ]]]]);
            }

            return Http::response([]);
        });
    }

    /** @return list<array<string,mixed>> */
    private function accountsOf(string $organisation): array
    {
        if ($organisation !== 'org-1') {
            return [['id' => 'nova-1', 'name' => 'Nova Ads', 'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE']];
        }

        return array_map(static fn (int $n): array => [
            'id' => sprintf('acct-%02d', $n), 'name' => sprintf('Account %02d', $n), 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'ACTIVE',
        ], range(1, 30));
    }

    private function signIn(Project $project): ProviderConnection
    {
        $url = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/snapchat/oauth/start', ['project_id' => $project->id, 'return_portal' => 'agency'])
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        $location = (string) $this->get("/api/v1/oauth/ads/snapchat/callback?code=abc&state={$query['state']}")->headers->get('Location');
        $this->assertStringContainsString('outcome=connected', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);

        return ProviderConnection::withoutGlobalScopes()->findOrFail((string) $back['link']);
    }

    private function directory(Project $project, ProviderConnection $connection, string $what)
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->getJson("/api/v1/projects/{$project->id}/integrations/link/{$connection->id}/{$what}");
    }

    private function confirm(Project $project, ProviderConnection $connection, string $externalId, ?string $organisation)
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->postJson("/api/v1/projects/{$project->id}/integrations/link/{$connection->id}/accounts", [
                'external_id' => $externalId, 'organisation' => $organisation,
            ]);
    }

    private function bind(Project $project, ProviderConnection $connection, ExternalAccount $account): void
    {
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->postJson("/api/v1/projects/{$project->id}/integrations/bindings/batch", [
                'connection_id' => $connection->id, 'external_account_ids' => [$account->id], 'purpose' => 'advertising',
            ])->assertCreated();
    }

    private function stored(ProviderConnection $connection, string $externalId, string $name): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $connection->tenant_id, 'provider_connection_id' => $connection->getKey(), 'provider' => $connection->provider,
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $name, 'currency' => 'SAR',
            'parent_external_id' => 'org-1', 'parent_name' => 'Acme Group', 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function member(array $permissions): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'u-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        return $user;
    }

    private function project(ClientWorkspace $workspace, string $name): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $workspace->tenant_id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }
}
