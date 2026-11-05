<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\IntegrationStatusResolver;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Unit\IntegrationStatusMatrixTest;

/**
 * INTEG-STATUS-MODEL-001 (contract §1.13) — the five statuses on the REAL endpoints, with real rows.
 *
 * The pure rules are proven in {@see IntegrationStatusMatrixTest}; this file proves that the hub,
 * the connection list and the account inventory CALL them, that the fields are additive, that one tenant's
 * connection never resolves for another, and that nothing secret rides along.
 *
 * Every test here would fail if the endpoint went back to deriving its own word: the assertions are on the
 * statuses a person would read, not on the helper that produced them.
 */
final class IntegrationStatusModelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->user = User::create(['name' => 'Owner One', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    /* ------------------------------------------------------------------ the hub */

    public function test_the_hub_row_carries_five_statuses_and_every_legacy_field_is_still_there(): void
    {
        $c = $this->connection();
        $this->bind($this->project('P'), $this->account($c, 'act_1'));

        $row = $this->hub()['connections'][0];

        $this->assertSame(['authorisation', 'binding', 'sync', 'freshness', 'error'], array_keys($row['statuses']));
        $this->assertArrayHasKey('status_profile', $row);
        foreach (['id', 'provider', 'label', 'label_ar', 'connection_name', 'authorised_by', 'authorised_at', 'selected_accounts', 'discovered_accounts',
            'connection_state', 'sync_state', 'needs_attention', 'last_success_at', 'next_sync_at', 'projects', 'has_parent',
            'discovery_blocked_reason', 'token_expires_at'] as $legacy) {
            $this->assertArrayHasKey($legacy, $row, "legacy field $legacy was removed or renamed");
        }
    }

    /** Discovered is not linked. */
    public function test_seventeen_discovered_and_none_chosen_is_discovered_unbound_not_bound(): void
    {
        $c = $this->connection();
        foreach (range(1, 17) as $i) {
            $this->account($c, 'act_'.$i);
        }

        $s = $this->hub()['connections'][0]['statuses'];

        $this->assertSame('discovered_unbound', $s['binding']['state']);
        $this->assertSame(17, $s['binding']['discovered']);
        $this->assertSame(0, $s['binding']['selected']);
        $this->assertSame('never_started', $s['sync']['state']);
        $this->assertSame('connected', $s['authorisation']['state'], 'the grant is fine; that is a different fact');
    }

    public function test_choosing_one_makes_the_binding_bound_with_the_counts(): void
    {
        $c = $this->connection();
        $chosen = $this->account($c, 'act_chosen');
        foreach (range(1, 16) as $i) {
            $this->account($c, 'act_o'.$i);
        }
        $this->bind($this->project('P'), $chosen);

        $b = $this->hub()['connections'][0]['statuses']['binding'];

        $this->assertSame('bound', $b['state']);
        $this->assertSame([1, 17], [$b['selected'], $b['discovered']]);
    }

    /** Connected is not synced. */
    public function test_a_bound_account_that_never_synced_is_connected_but_never_started_and_unknown(): void
    {
        $c = $this->connection();
        $this->bind($this->project('P'), $this->account($c));

        $s = $this->hub()['connections'][0]['statuses'];

        $this->assertSame('connected', $s['authorisation']['state']);
        $this->assertSame('bound', $s['binding']['state']);
        $this->assertSame('never_started', $s['sync']['state']);
        $this->assertSame('unknown', $s['freshness']['state']);
        $this->assertNull($s['freshness']['as_of']);
    }

    /** An API call that succeeded is not complete data. */
    public function test_a_partial_mapping_run_is_neither_a_quiet_success_nor_fresh(): void
    {
        $c = $this->connection();
        $a = $this->account($c);
        $this->bind($this->project('P'), $a);
        $this->stamp($a, ['last_synced_at' => Carbon::now()->subMinutes(10), 'last_sync_error_category' => 'unmapped_rows']);
        $this->syncRun($a, 'partial_mapping', Carbon::now()->subMinutes(10));

        $s = $this->hub()['connections'][0]['statuses'];

        $this->assertSame('partial', $s['sync']['state']);
        $this->assertSame('partial', $s['freshness']['state']);
        $this->assertSame('attention', $s['error']['state']);
        $this->assertSame('unmapped_rows', $s['error']['category']);
        $this->assertSame('connected', $s['authorisation']['state']);
    }

    /** Withheld values never display as a trusted zero or as healthy. */
    public function test_currency_unknown_is_withheld_never_fresh_and_never_a_clean_sync(): void
    {
        $c = $this->connection();
        $a = $this->account($c);
        $this->bind($this->project('P'), $a);
        $this->stamp($a, ['last_synced_at' => Carbon::now()->subMinutes(5), 'last_sync_error_category' => 'currency_unknown']);
        $this->syncRun($a, 'success', Carbon::now()->subMinutes(5));

        $s = $this->hub()['connections'][0]['statuses'];

        $this->assertSame('withheld', $s['freshness']['state']);
        $this->assertSame('currency_unknown', $s['freshness']['detail']);
        $this->assertSame('partial', $s['sync']['state']);
        $this->assertNotSame('succeeded', $s['sync']['state']);
        $this->assertSame('set_currency', $s['error']['action']['code']);
        $this->assertStringContainsString('عملة', $s['error']['action']['label_ar']);
    }

    public function test_an_awaiting_assignment_run_withholds_and_pauses_rather_than_passing(): void
    {
        $c = $this->connection();
        $a = $this->account($c);
        $this->bind($this->project('P'), $a);
        $this->stamp($a, ['last_sync_error_category' => 'awaiting_assignment']);
        $this->syncRun($a, 'awaiting_assignment', Carbon::now()->subMinutes(5));

        $s = $this->hub()['connections'][0]['statuses'];

        $this->assertSame('paused', $s['sync']['state']);
        $this->assertSame('withheld', $s['freshness']['state']);
    }

    public function test_a_stale_running_row_is_not_believed_but_a_fresh_one_is(): void
    {
        $c = $this->connection();
        $a = $this->account($c);
        $this->bind($this->project('P'), $a);
        $this->stamp($a, ['last_synced_at' => Carbon::now()->subHour()]);
        $this->syncRun($a, 'success', Carbon::now()->subHour());
        $this->syncRun($a, 'running', Carbon::now()->subHours(3));

        $this->assertSame('succeeded', $this->hub()['connections'][0]['statuses']['sync']['state']);

        $this->syncRun($a, 'running', Carbon::now()->subMinutes(5));

        $this->assertSame('running', $this->hub()['connections'][0]['statuses']['sync']['state']);
    }

    /** Expired token: the new object says so; the shipped word is untouched (additive). */
    public function test_an_expired_token_is_reauth_required_while_the_legacy_word_is_unchanged(): void
    {
        $c = $this->connection('tiktok');
        $c->forceFill(['token_expires_at' => Carbon::now()->subDay()])->save();
        $a = $this->account($c, 'adv_1', provider: 'tiktok');
        $this->bind($this->project('P'), $a, 'tiktok');
        $this->stamp($a, ['last_synced_at' => Carbon::now()->subMinutes(20)]);
        $this->syncRun($a, 'success', Carbon::now()->subMinutes(20), 'tiktok');

        $row = $this->hub()['connections'][0];

        $this->assertSame('reauth_required', $row['statuses']['authorisation']['state']);
        $this->assertSame('token_expired', $row['statuses']['error']['category']);
        $this->assertSame('أعد المصادقة', $row['statuses']['error']['action']['label_ar']);
        $this->assertSame('succeeded', $row['statuses']['sync']['state'], 'the last sync is reported as it was');
        $this->assertSame('CONNECTED', $row['connection_state'], 'the legacy field keeps its meaning');
        $this->assertSame('polling', $row['status_profile']['freshness_mode']);
    }

    /** A refresh failure stops only that connection. */
    public function test_a_refresh_failure_on_one_connection_does_not_touch_its_sibling(): void
    {
        $ok = $this->connection('snapchat');
        Carbon::setTestNow(Carbon::now()->addSecond());
        $bad = $this->connection('snapchat');
        Carbon::setTestNow();
        $bad->forceFill(['status' => 'error', 'last_error' => 'refresh failed: SECRET-REFRESH-TOKEN-123'])->save();

        $rows = collect($this->hub()['connections'])->keyBy('id');

        $this->assertSame('connected', $rows[(string) $ok->id]['statuses']['authorisation']['state']);
        $this->assertSame('none', $rows[(string) $ok->id]['statuses']['error']['state']);
        $this->assertSame('reauth_required', $rows[(string) $bad->id]['statuses']['authorisation']['state']);
        $this->assertSame('refresh_failed', $rows[(string) $bad->id]['statuses']['error']['category']);
        $this->assertStringNotContainsString('SECRET-REFRESH-TOKEN-123', json_encode($this->hub(), JSON_UNESCAPED_UNICODE));
    }

    /** API-key provider: no expiry, no refresh, its own failure word. */
    public function test_an_api_key_provider_has_no_expiry_and_its_own_invalid_state(): void
    {
        $c = $this->connection('openai_ads');
        $c->forceFill(['token_expires_at' => Carbon::now()->subYear()])->save();

        $row = $this->hub()['connections'][0];
        $this->assertSame('connected', $row['statuses']['authorisation']['state']);
        $this->assertSame('api_key_has_no_expiry', $row['statuses']['authorisation']['detail']);
        $this->assertSame('api_key', $row['status_profile']['auth']);
        $this->assertFalse($row['status_profile']['refresh']);

        $c->forceFill(['status' => 'error'])->save();
        $row = $this->hub()['connections'][0];
        $this->assertSame('api_key_invalid', $row['statuses']['authorisation']['state']);
        $this->assertSame('replace_api_key', $row['statuses']['error']['action']['code']);
    }

    public function test_the_hub_still_reports_a_refused_grant_and_an_open_run_both(): void
    {
        $c = $this->connection();
        $c->forceFill(['insights_denied_at' => Carbon::now()])->save();
        $a = $this->account($c);
        $this->bind($this->project('P'), $a);
        $this->syncRun($a, 'running', Carbon::now()->subMinutes(2));

        $row = $this->hub()['connections'][0];

        $this->assertSame('reauth_required', $row['statuses']['authorisation']['state']);
        $this->assertSame('running', $row['statuses']['sync']['state']);
        $this->assertSame('REAUTH_REQUIRED', $row['connection_state']);
        $this->assertSame('SYNCING', $row['sync_state']);
    }

    /* ------------------------------------------------------------------ the connection list */

    public function test_the_connection_list_carries_statuses_per_connection_including_a_removed_one(): void
    {
        $live = $this->connection();
        $gone = $this->connection();
        $gone->forceFill(['status' => 'disconnected'])->save();
        $this->bind($this->project('P'), $this->account($live));

        $rows = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/connections')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('connected', $rows[(string) $live->id]['statuses']['authorisation']['state']);
        $this->assertSame('bound', $rows[(string) $live->id]['statuses']['binding']['state']);
        $this->assertSame('removed', $rows[(string) $gone->id]['statuses']['authorisation']['state']);
        $this->assertSame('connection_removed', $rows[(string) $gone->id]['statuses']['error']['category']);
        // The shipped fields are untouched.
        $this->assertSame('connected', $rows[(string) $live->id]['status']);
        $this->assertArrayHasKey('ever_discovered', $rows[(string) $live->id]);
    }

    /* ------------------------------------------------------------------ the account panel */

    public function test_the_inventory_says_discovered_unbound_for_an_account_nobody_chose(): void
    {
        $c = $this->connection();
        $a = $this->account($c, 'act_free');

        $row = $this->inventoryRow($a);

        $this->assertSame('discovered_unbound', $row['statuses']['binding']['state']);
        $this->assertSame('never_started', $row['statuses']['sync']['state']);
        $this->assertSame('unknown', $row['statuses']['freshness']['state']);
        $this->assertNull($row['health'], 'the legacy field is unchanged');
    }

    /** A sync that is still running while the link is cut: both are said. */
    public function test_a_running_sync_during_detach_is_running_and_deselected_at_once(): void
    {
        $c = $this->connection();
        $a = $this->account($c);
        $binding = $this->bind($this->project('P'), $a);
        $this->syncRun($a, 'running', Carbon::now()->subMinutes(3));
        $binding->forceFill(['is_active' => false])->save();

        $row = $this->inventoryRow($a);

        $this->assertSame('deselected', $row['statuses']['binding']['state']);
        $this->assertSame('running', $row['statuses']['sync']['state']);
        $this->assertSame('running_after_detach', $row['statuses']['sync']['detail']);
    }

    public function test_an_account_of_a_removed_connection_with_its_link_cut_is_detached(): void
    {
        $c = $this->connection();
        $a = $this->account($c);
        $binding = $this->bind($this->project('P'), $a);
        $binding->forceFill(['is_active' => false])->save();
        $c->forceFill(['status' => 'revoked'])->save();

        $row = $this->inventoryRow($a);

        $this->assertSame('revoked', $row['statuses']['authorisation']['state']);
        $this->assertSame('detached', $row['statuses']['binding']['state']);
    }

    public function test_a_never_synced_bound_account_and_an_access_lost_one_differ_per_status(): void
    {
        $c = $this->connection();
        $fresh = $this->account($c, 'act_new');
        $lost = $this->account($c, 'act_lost');
        $p = $this->project('P');
        $this->bind($p, $fresh);
        $this->bind($p, $lost);
        $this->stamp($lost, ['access_lost_at' => Carbon::now()->subDay(), 'last_synced_at' => Carbon::now()->subDays(4)]);

        $a = $this->inventoryRow($fresh)['statuses'];
        $b = $this->inventoryRow($lost)['statuses'];

        $this->assertSame(['connected', 'bound', 'never_started', 'unknown', 'none'], $this->states($a));
        $this->assertSame(['access_lost', 'bound', 'succeeded', 'stale', 'error'], $this->states($b));
    }

    public function test_a_commerce_store_goes_through_the_same_object(): void
    {
        $c = $this->connection('salla');
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $c->id, 'provider' => 'salla',
            'external_id' => 'store_1', 'name' => 'Store', 'account_type' => 'store', 'status' => 'active',
        ]);

        $row = $this->inventoryRow($store);

        $this->assertSame('commerce', $row['status_profile']['kind']);
        $this->assertSame('discovered_unbound', $row['statuses']['binding']['state']);
    }

    /* ------------------------------------------------------------------ isolation and secrets */

    public function test_another_tenants_connection_never_resolves(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        $theirs = $this->connection('meta', $other);
        $this->account($theirs, 'act_theirs', $other);
        $mine = $this->connection();

        $hub = $this->hub()['connections'];
        $list = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/connections')->assertOk()->json('data');

        $this->assertSame([(string) $mine->id], array_column($hub, 'id'));
        $this->assertSame([(string) $mine->id], array_column($list, 'id'));
        $this->assertStringNotContainsString((string) $theirs->id, json_encode([$hub, $list]));

        // Defence in depth: even handed the foreign row directly, the batch resolver refuses it.
        $resolved = app(IntegrationStatusResolver::class)->forConnections(
            ProviderConnection::withoutGlobalScopes()->whereKey([$theirs->id, $mine->id])->get(),
            (string) $this->tenant->id,
        );
        $this->assertSame([(string) $mine->id], array_keys($resolved));
    }

    public function test_no_secret_or_provider_message_reaches_any_status(): void
    {
        $c = $this->connection();
        $c->forceFill(['status' => 'error', 'last_error' => 'Bearer SECRET-ABC-999 rejected'])->save();
        $this->account($c);

        $json = json_encode([
            $this->hub(),
            $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/connections')->json(),
            $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/accounts')->json(),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('SECRET-ABC-999', $json);
        $this->assertStringNotContainsString('"payload"', $json);
    }

    public function test_the_statuses_are_not_stored_anywhere(): void
    {
        $this->assertFalse(Schema::hasColumn('provider_connections', 'statuses'));
        $this->assertFalse(Schema::hasColumn('external_accounts', 'statuses'));
    }

    /* ------------------------------------------------------------------ helpers */

    /** @return list<string> */
    private function states(array $statuses): array
    {
        return array_values(array_map(static fn (array $s): string => $s['state'], $statuses));
    }

    /** @return array<string,mixed> */
    private function hub(): array
    {
        return $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/integrations/hub')->assertOk()->json('data');
    }

    /** @return array<string,mixed> */
    private function inventoryRow(ExternalAccount $account): array
    {
        $rows = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/accounts?per_page=100')->assertOk()->json('data.accounts');

        return collect($rows)->firstWhere('id', (string) $account->id) ?? $this->fail('account not in the inventory');
    }

    private function connection(string $provider = 'meta', ?Tenant $tenant = null): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => $provider === 'openai_ads' ? 'api_key' : 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => ucfirst($provider), 'scope' => 'project_only', 'status' => 'connected',
            'scopes' => $provider === 'meta' ? ['ads_read'] : null,
        ]);
    }

    private function account(ProviderConnection $c, string $externalId = 'act_1', ?Tenant $tenant = null, string $provider = 'meta'): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'provider_connection_id' => $c->id, 'provider' => $c->provider ?: $provider,
            'external_id' => $externalId, 'name' => 'Account '.$externalId, 'account_type' => 'ad_account', 'status' => 'active',
        ]);
    }

    private function project(string $name): Project
    {
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed',
        ]);

        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function bind(Project $project, ExternalAccount $account, string $provider = 'meta'): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'client_workspace_id' => $project->client_workspace_id,
            'external_account_id' => $account->id, 'provider' => $account->provider ?: $provider, 'purpose' => 'advertising', 'is_active' => true,
        ]);
    }

    /** @param array<string,mixed> $attrs */
    private function stamp(ExternalAccount $a, array $attrs): void
    {
        $a->forceFill($attrs)->save();
    }

    private function syncRun(ExternalAccount $a, string $status, Carbon $startedAt, string $provider = 'meta'): void
    {
        MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'external_account_id' => $a->id, 'provider' => $a->provider ?: $provider, 'status' => $status,
            'window_start' => $startedAt->copy()->subDays(7)->toDateString(), 'window_end' => $startedAt->toDateString(),
            'started_at' => $startedAt, 'finished_at' => $status === 'running' ? null : $startedAt->copy()->addMinute(),
            'metrics_upserted' => $status === 'success' ? 5 : 0,
        ]);
    }
}
