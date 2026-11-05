<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PendingProjectLink;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetricDefinitionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * INTEG-PROJECT-LINK-001 (Owner 2026-11-06) — «الأساس ربط المصدر بالمشروع».
 *
 * Every link starts FROM a project and ends back on it: project → platform → organisation → ad account →
 * «تم الربط» → the project's page. The project is fixed for the whole journey and survives the provider's
 * consent screen on the SERVER (single-use state, then {@see PendingProjectLink}) — never as something the
 * browser can edit into another project.
 *
 * What these prove: the return lands on the project; a refusal or a failure opens nothing; the project is
 * re-proven on the callback; a link made through the journey is visible to that project only; and money is
 * described the way the pipeline treats it (original kept, converted to USD, or withheld — never assumed).
 */
final class ProjectFirstLinkJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->owner = $this->member($this->tenant, Permission::pluck('key')->all(), 'Owner One');
        $this->projectA = $this->project($this->tenant, 'Project A');
        $this->projectB = $this->project($this->tenant, 'Project B');
    }

    // ── the return from the provider ─────────────────────────────────────────────────────────────

    public function test_the_return_lands_on_the_project_page_and_the_project_is_kept_on_the_server_not_in_the_address(): void
    {
        Queue::fake();
        $this->configure('meta');
        $this->fakeMeta([['id' => 'act_1', 'name' => 'Main', 'currency' => 'SAR', 'timezone_name' => 'Asia/Riyadh', 'account_status' => 1]]);

        $state = $this->start('meta', ['project_id' => $this->projectA->id, 'return_portal' => 'agency']);
        $location = (string) $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->headers->get('Location');

        $connection = ProviderConnection::withoutGlobalScopes()->firstOrFail();
        $this->assertStringContainsString("/agency/projects/{$this->projectA->id}/integrations?", $location);
        $this->assertStringContainsString('outcome=connected', $location);
        $this->assertStringContainsString('link='.$connection->id, $location);
        // The project is a place to land, never a parameter that decides anything.
        $this->assertStringNotContainsString('project=', $location);

        $this->assertTrue(PendingProjectLink::holds((string) $connection->id, $this->tenant->id, $this->projectA->id, (int) $this->owner->id));

        // Discovery is not a binding: one account catalogued, nothing linked, nothing queued.
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();

        $link = $this->link($this->projectA, $connection)->assertOk()->json('data');
        $this->assertTrue($link['pending']);
        $this->assertSame('Project A', $link['project']['name']);
        $this->assertSame([], $link['accounts'], 'a discovered account was shown as linked');
    }

    public function test_the_default_portal_is_the_advertisers_and_an_unknown_one_is_refused(): void
    {
        $this->configure('meta');
        $this->fakeMeta([]);

        $state = $this->start('meta', ['project_id' => $this->projectA->id]);
        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")
            ->assertRedirectContains("/app/projects/{$this->projectA->id}/integrations?");

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/meta/oauth/start', ['project_id' => $this->projectA->id, 'return_portal' => 'admin'])
            ->assertStatus(422);
    }

    public function test_a_refusal_at_the_provider_returns_to_the_project_with_nothing_opened(): void
    {
        $this->configure('snapchat');
        Http::fake();

        $state = $this->start('snapchat', ['project_id' => $this->projectA->id, 'return_portal' => 'agency']);

        $this->get("/api/v1/oauth/ads/snapchat/callback?error=access_denied&state={$state}")
            ->assertRedirectContains("/agency/projects/{$this->projectA->id}/integrations?")
            ->assertRedirectContains('outcome=denied');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
        Http::assertNothingSent();

        // The state was spent by the refusal: replaying it with a code opens nothing either.
        $this->get("/api/v1/oauth/ads/snapchat/callback?code=abc&state={$state}")->assertRedirectContains('outcome=invalid_state');
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
    }

    public function test_a_failed_token_exchange_returns_to_the_project_with_nothing_opened(): void
    {
        $this->configure('meta');
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['error' => ['message' => 'Invalid verification code format.']], 400));

        $state = $this->start('meta', ['project_id' => $this->projectA->id]);

        $this->get("/api/v1/oauth/ads/meta/callback?code=bad&state={$state}")
            ->assertRedirectContains("/app/projects/{$this->projectA->id}/integrations?")
            ->assertRedirectContains('outcome=failed');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->where('status', 'connected')->count());
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    public function test_a_project_deleted_while_the_person_was_at_the_provider_is_refused_before_the_code_is_exchanged(): void
    {
        $this->configure('meta');
        $this->fakeMeta([['id' => 'act_1', 'name' => 'Main', 'currency' => 'SAR', 'account_status' => 1]]);

        $state = $this->start('meta', ['project_id' => $this->projectA->id]);
        $this->projectA->delete();

        $location = (string) $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->headers->get('Location');

        $this->assertStringContainsString('outcome=project_refused', $location);
        $this->assertStringContainsString('/app/integrations?', $location);
        $this->assertStringNotContainsString($this->projectA->id, $location);
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_a_person_who_lost_the_right_to_link_while_at_the_provider_is_refused_on_the_callback(): void
    {
        $this->configure('meta');
        $this->fakeMeta([['id' => 'act_1', 'name' => 'Main', 'currency' => 'SAR', 'account_status' => 1]]);

        $state = $this->start('meta', ['project_id' => $this->projectA->id]);
        // Their role is withdrawn before they come back.
        DB::table('role_user')->where('user_id', $this->owner->id)->delete();
        $this->owner->unsetRelations();

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=project_refused');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    // ── who may start, and for which project ─────────────────────────────────────────────────────

    public function test_starting_for_a_project_needs_the_right_to_link_to_it(): void
    {
        $this->configure('meta');

        // No integrations.connect at all.
        $viewer = $this->member($this->tenant, ['projects.view', 'integrations.view'], 'Viewer');
        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/integrations/meta/oauth/start', ['project_id' => $this->projectA->id])
            ->assertForbidden();

        // May connect, but is a member of no project and sees none: not THIS project.
        $outsider = $this->member($this->tenant, ['projects.view', 'integrations.view', 'integrations.connect'], 'Outsider');
        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/v1/integrations/meta/oauth/start', ['project_id' => $this->projectA->id])
            ->assertForbidden();

        // Another tenant's project does not exist as far as this request can tell.
        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rival-'.uniqid(), 'status' => 'active']);
        $theirs = $this->project($rival, 'Rival Project');
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/meta/oauth/start', ['project_id' => $theirs->id])
            ->assertStatus(422);
    }

    // ── resuming: only the same user, tenant and project ─────────────────────────────────────────

    public function test_a_link_minted_for_one_user_and_project_is_pending_for_nobody_and_nothing_else(): void
    {
        $connection = $this->connection($this->tenant, 'meta');
        PendingProjectLink::put((string) $connection->id, $this->tenant->id, $this->projectA->id, (int) $this->owner->id);

        // The same person asking about ANOTHER project (the address was edited): not pending.
        $this->assertFalse($this->link($this->projectB, $connection)->assertOk()->json('data.pending'));

        // Another person of the same tenant asking about the right project: not pending.
        $colleague = $this->member($this->tenant, Permission::pluck('key')->all(), 'Colleague');
        $this->assertFalse($this->link($this->projectA, $connection, $colleague)->assertOk()->json('data.pending'));

        // The person it was minted for, on the project it was minted for.
        $this->assertTrue($this->link($this->projectA, $connection)->assertOk()->json('data.pending'));

        // A record minted in another tenant for the same ids holds nothing here.
        PendingProjectLink::put((string) $connection->id, 'another-tenant', $this->projectA->id, (int) $this->owner->id);
        $this->assertFalse($this->link($this->projectA, $connection)->assertOk()->json('data.pending'));
    }

    public function test_another_tenants_connection_is_not_found_and_its_accounts_are_never_listed(): void
    {
        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rival-'.uniqid(), 'status' => 'active']);
        $theirs = $this->connection($rival, 'meta');
        $this->account($theirs, 'act_rival', 'Rival Secret Account', 'SAR');

        $response = $this->link($this->projectA, $theirs)->assertNotFound();
        $this->assertStringNotContainsString('Rival Secret Account', (string) $response->getContent());

        // And the rival cannot read OUR project's link at all.
        $ours = $this->connection($this->tenant, 'meta');
        $rivalOwner = $this->member($rival, Permission::pluck('key')->all(), 'Rival Owner');
        app(TenantContext::class)->setTenantId($rival->id);
        $status = $this->actingAs($rivalOwner, 'sanctum')->withHeader('X-Project-Id', $this->projectA->id)
            ->getJson("/api/v1/projects/{$this->projectA->id}/integrations/link/{$ours->id}")->status();
        $this->assertContains($status, [403, 404]);
    }

    public function test_the_link_summary_needs_permission(): void
    {
        $connection = $this->connection($this->tenant, 'meta');
        $bare = $this->member($this->tenant, ['projects.view'], 'Bare');

        $this->assertContains($this->link($this->projectA, $connection, $bare)->status(), [403, 404]);
    }

    // ── isolation of what the journey links ──────────────────────────────────────────────────────

    public function test_an_account_linked_through_the_journey_is_this_projects_only(): void
    {
        Queue::fake();
        $connection = $this->connection($this->tenant, 'meta');
        $chosen = $this->account($connection, 'act_1', 'Chosen Account', 'USD', 'Acme Business');
        $this->account($connection, 'act_2', 'Never Chosen', 'USD', 'Acme Business');

        $this->confirm($this->projectA, $connection, [$chosen->id])->assertCreated()->assertJsonPath('data.connected', 1);

        // Project A: exactly the chosen account, with its organisation.
        $a = $this->link($this->projectA, $connection)->assertOk()->json('data');
        $this->assertSame(['Chosen Account'], array_column($a['accounts'], 'name'));
        $this->assertSame('Acme Business', $a['accounts'][0]['organisation']);
        $this->assertSame(['state' => 'reporting', 'rate' => 1, 'rate_date' => null], $a['accounts'][0]['money']);

        // Project B of the same tenant: nothing of it, in the link summary or in its own bindings.
        $b = $this->link($this->projectB, $connection)->assertOk();
        $this->assertSame([], $b->json('data.accounts'));
        $this->assertStringNotContainsString('Chosen Account', (string) $b->getContent());
        $bindingsOfB = $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $this->projectB->id)
            ->getJson("/api/v1/projects/{$this->projectB->id}/integrations")->assertOk();
        $this->assertSame([], $bindingsOfB->json('data'));

        // Only the chosen account is bound, and only once.
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());
        $this->assertSame((string) $chosen->id, (string) ProjectIntegrationBinding::withoutGlobalScopes()->value('external_account_id'));
    }

    public function test_confirming_the_same_account_twice_makes_one_binding(): void
    {
        Queue::fake();
        $connection = $this->connection($this->tenant, 'meta');
        $account = $this->account($connection, 'act_1', 'Main', 'USD');

        $this->confirm($this->projectA, $connection, [$account->id])->assertCreated();
        $this->confirm($this->projectA, $connection, [$account->id])->assertSuccessful();

        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $account->id)->count());
        $this->assertCount(1, $this->link($this->projectA, $connection)->json('data.accounts'));
    }

    /**
     * The engine's rule, kept: one ad account feeds ONE project at a time. It is not shared silently, and
     * it is not shared on request either — the refusal names the fact and nothing is bound.
     */
    public function test_an_account_linked_to_another_project_is_refused_not_shared(): void
    {
        Queue::fake();
        $connection = $this->connection($this->tenant, 'meta');
        $account = $this->account($connection, 'act_1', 'Main', 'USD');
        $this->confirm($this->projectA, $connection, [$account->id])->assertCreated();

        $refused = $this->confirm($this->projectB, $connection, [$account->id])->assertStatus(409);
        $this->assertSame((string) $this->projectA->id, $refused->json('meta.assigned_project_id'));

        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectB->id)->count());
        $this->assertSame([], $this->link($this->projectB, $connection)->json('data.accounts'));
        $this->assertCount(1, $this->link($this->projectA, $connection)->json('data.accounts'));
    }

    public function test_removing_a_source_from_one_project_leaves_the_other_projects_link_untouched(): void
    {
        Queue::fake();
        $connection = $this->connection($this->tenant, 'meta');
        $forA = $this->account($connection, 'act_a', 'Account of A', 'USD');
        $forB = $this->account($connection, 'act_b', 'Account of B', 'USD');
        $this->confirm($this->projectA, $connection, [$forA->id])->assertCreated();
        $this->confirm($this->projectB, $connection, [$forB->id])->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $this->projectA->id)
            ->postJson("/api/v1/projects/{$this->projectA->id}/integrations/accounts/{$forA->id}/unlink")->assertOk();

        $this->assertSame([], $this->link($this->projectA, $connection)->json('data.accounts'));
        $this->assertSame(['Account of B'], array_column($this->link($this->projectB, $connection)->json('data.accounts'), 'name'));
        $this->assertTrue((bool) ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectB->id)->value('is_active'));
    }

    public function test_a_member_without_the_right_cannot_confirm_a_link(): void
    {
        $connection = $this->connection($this->tenant, 'meta');
        $account = $this->account($connection, 'act_1', 'Main', 'USD');
        $viewer = $this->member($this->tenant, ['projects.view', 'projects.view.all', 'integrations.view'], 'Viewer');

        $this->confirm($this->projectA, $connection, [$account->id], $viewer)->assertForbidden();
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    // ── money: described as the pipeline treats it, never assumed ────────────────────────────────

    public function test_each_linked_accounts_money_is_described_reporting_converted_or_withheld(): void
    {
        Queue::fake();
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-06-01']);
        $connection = $this->connection($this->tenant, 'meta');
        $usd = $this->account($connection, 'act_usd', 'A Dollars', 'USD');
        $sar = $this->account($connection, 'act_sar', 'B Riyals', 'SAR');
        $eur = $this->account($connection, 'act_eur', 'C Euros', 'EUR');
        $unknown = $this->account($connection, 'act_unknown', 'D Unknown', null);
        $this->confirm($this->projectA, $connection, [$usd->id, $sar->id, $eur->id, $unknown->id])->assertCreated();

        $data = $this->link($this->projectA, $connection)->assertOk()->json('data');
        $money = collect($data['accounts'])->mapWithKeys(fn (array $a): array => [$a['name'] => $a['money']['state']])->all();

        $this->assertSame('USD', $data['reporting_currency']);
        $this->assertSame([
            'A Dollars' => 'reporting',
            'B Riyals' => 'converted',
            // A floating currency with no rate on file: linked, money withheld, not converted at a guess.
            'C Euros' => 'withheld_no_rate',
            // The provider has not stated a currency: withheld, never read as dollars.
            'D Unknown' => 'withheld_unknown',
        ], $money);

        $riyals = collect($data['accounts'])->firstWhere('name', 'B Riyals');
        $this->assertSame('SAR', $riyals['currency']);
        $this->assertEqualsWithDelta(1 / 3.75, $riyals['money']['rate'], 0.0001);
    }

    /**
     * Owner 2026-11-06 — «كل العملات تُحوَّل إلى الدولار». The conversion rule is the pipeline's own and is
     * not changed here; this proves a riyal account linked THROUGH THE JOURNEY keeps its original amount in
     * riyals and stores the converted figure in dollars.
     */
    public function test_a_riyal_account_linked_through_the_journey_stores_original_sar_and_converted_usd(): void
    {
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-06-01']);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'openai_ads',
            tokens: new OAuthTokens('sk-ads-live-0000beef', null, null),
            connectionName: 'ChatGPT Ads',
            credentialType: 'api_key',
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'openai_ads',
            'account_type' => 'ad_account', 'external_id' => 'adacct_123', 'name' => 'Riyadh store', 'currency' => 'SAR',
            'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);

        Queue::fake();
        $this->confirm($this->projectA, $connection, [$account->id])->assertCreated();

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_contains($url, '/conversions/insights')) {
                return Http::response(['object' => 'list', 'data' => [], 'count' => 0]);
            }
            if (str_contains($url, '/ad_account/insights')) {
                return str_contains($url, 'aggregation_level=campaign')
                    ? Http::response(['object' => 'list', 'has_more' => false, 'data' => [[
                        'id' => 'r1', 'readable_time' => '2026-11-02', 'campaign_id' => 'cmp_1', 'impressions' => 9000, 'clicks' => 150, 'spend' => 245.07,
                    ]]])
                    : Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
            }
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            return Http::response(['id' => 'adacct_123', 'name' => 'Riyadh store', 'status' => 'active', 'timezone' => 'Asia/Riyadh', 'currency_code' => 'SAR', 'review' => ['status' => 'approved']]);
        });

        app()->call([new SyncAccountStructureJob($account->id), 'handle']);
        app()->call([new SyncAccountMetricsJob($account->id, '2026-11-01', '2026-11-05'), 'handle']);

        $spend = DB::table('daily_metrics')->where('metric_key', 'spend')->where('metric_date', '2026-11-02')->first();
        $this->assertNotNull($spend, 'the linked account stored no spend');
        $this->assertSame((string) $this->projectA->id, (string) $spend->project_id);
        $this->assertSame('SAR', $spend->original_currency);
        $this->assertEqualsWithDelta(245.07, (float) $spend->original_amount, 0.001);
        $this->assertEqualsWithDelta(245.07 / 3.75, (float) $spend->value, 0.01);

        // Nothing of it was written under the other project.
        $this->assertSame(0, DB::table('daily_metrics')->where('project_id', $this->projectB->id)->count());
        $this->assertSame('converted', $this->link($this->projectA, $connection)->json('data.accounts.0.money.state'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    private function link(Project $project, ProviderConnection $connection, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->getJson("/api/v1/projects/{$project->id}/integrations/link/{$connection->id}");
    }

    /** @param  list<string>  $accountIds */
    private function confirm(Project $project, ProviderConnection $connection, array $accountIds, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->postJson("/api/v1/projects/{$project->id}/integrations/bindings/batch", [
                'connection_id' => $connection->id, 'external_account_ids' => $accountIds, 'purpose' => 'advertising',
            ]);
    }

    private function start(string $platform, array $body): string
    {
        $url = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/integrations/{$platform}/oauth/start", $body)
            ->assertOk()
            ->json('data.authorization_url');

        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    /** @param  list<array<string,mixed>>  $accounts */
    private function fakeMeta(array $accounts): void
    {
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) use ($accounts) {
            if (str_contains($request->url(), '/oauth/access_token')) {
                return Http::response(['access_token' => 'AT', 'expires_in' => 5184000]);
            }
            if (str_contains($request->url(), '/me/adaccounts')) {
                return Http::response(['data' => $accounts]);
            }

            return Http::response([], 404);
        });
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

    private function connection(Tenant $tenant, string $provider): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('token-'.uniqid(), null, null),
            connectionName: ucfirst($provider),
        );
    }

    private function account(ProviderConnection $connection, string $externalId, string $name, ?string $currency, ?string $organisation = null): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $connection->tenant_id, 'provider_connection_id' => $connection->getKey(), 'provider' => $connection->provider,
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $name, 'currency' => $currency,
            'parent_external_id' => $organisation === null ? null : 'biz_1', 'parent_name' => $organisation,
            'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
    }
}
