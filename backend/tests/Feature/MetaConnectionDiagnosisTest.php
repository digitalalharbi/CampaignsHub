<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-DIAGNOSIS-001 — each Meta connection, read from what the sync already recorded.
 *
 * Owner §15.1: per connection and account, the API error code, permissions, token state, accounts selected and
 * synced, last real sync, and why content is missing. No Graph call is made and nothing is written: that is what
 * lets it be opened at any time, and `integrations:meta-probe` remains the read that asks Meta.
 */
final class MetaConnectionDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'SECRET-TOKEN-VALUE';

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $this->tenant = Tenant::create(['name' => 'Tenant', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
    }

    public function test_a_dead_connection_states_its_code_its_action_and_its_accounts(): void
    {
        $connection = $this->connection();
        $connection->forceFill([
            'status' => 'error', 'scopes' => ['ads_read'],
            'last_error' => 'Error validating access token · code 190 · subcode 460 · trace AbC',
            'last_successful_sync_at' => Carbon::parse('2026-10-25 10:00:00'),
        ])->save();
        $this->account($connection, 'act_1');

        $row = $this->rows()[0];

        $this->assertSame('needs_reconnect', $row['state']);
        $this->assertSame('session_invalid', $row['refusal']['kind']);
        $this->assertSame(190, $row['refusal']['code']);
        $this->assertSame(460, $row['refusal']['subcode']);
        $this->assertSame('customer', $row['refusal']['actor']);
        $this->assertNotSame('', $row['refusal']['action_ar']);
        $this->assertSame(1, $row['accounts_selected']);
        $this->assertSame('act_1', $row['accounts'][0]['external_id']);
    }

    public function test_permission_scopes_read_from_the_stored_grant_and_unknown_is_not_missing(): void
    {
        $granted = $this->connection('a');
        $granted->forceFill(['scopes' => ['ads_read', 'business_management']])->save();
        $partial = $this->connection('b');
        $partial->forceFill(['scopes' => ['public_profile']])->save();
        $unknown = $this->connection('c');
        $unknown->forceFill(['scopes' => null])->save();

        $rows = collect($this->rows())->keyBy('id');

        $this->assertSame([], $rows[$granted->id]['scopes_missing']);
        $this->assertSame(['ads_read', 'business_management'], $rows[$partial->id]['scopes_missing']);
        $this->assertNull($rows[$unknown->id]['scopes_missing']);
    }

    public function test_a_token_past_its_expiry_is_named_while_still_marked_connected(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['token_expires_at' => now()->subDay()])->save();

        $row = $this->rows()[0];

        $this->assertSame('expired', $row['token']['state']);
        $this->assertSame('token_expired', $row['state']);
    }

    /** Connected and never synced is not «working» — Owner: no Connected or Live without a real sync. */
    public function test_a_connected_connection_that_never_synced_does_not_read_as_working(): void
    {
        $this->connection();

        $this->assertSame('never_synced', $this->rows()[0]['state']);
    }

    public function test_a_recent_real_sync_reads_as_syncing_and_an_old_one_as_stale(): void
    {
        $recent = $this->connection('a');
        $recent->forceFill(['last_successful_sync_at' => now()->subHours(2)])->save();
        $old = $this->connection('b');
        $old->forceFill(['last_successful_sync_at' => now()->subDays(5)])->save();

        $rows = collect($this->rows())->keyBy('id');

        $this->assertSame('syncing', $rows[$recent->id]['state']);
        $this->assertSame('stale', $rows[$old->id]['state']);
    }

    public function test_an_account_names_its_expired_media_and_its_own_last_structure_refusal(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['last_successful_sync_at' => now()->subHours(2)])->save();
        $refused = $this->account($connection, 'act_1');
        $healthy = $this->account($connection, 'act_2');
        $this->creatives($refused, 3, expired: 2);
        $this->creatives($healthy, 1, expired: 0);
        $this->syncRun($connection, 'act_1', 'failed', '(#200) Ad account owner has NOT grant ads_read permission · code 200', ['media' => ['asked' => 4, 'resolved' => 1]]);
        $this->syncRun($connection, 'act_2', 'success', null);

        $accounts = collect($this->rows()[0]['accounts'])->keyBy('external_id');

        $this->assertSame(3, $accounts['act_1']['creatives']);
        $this->assertSame(2, $accounts['act_1']['expired_media']);
        $this->assertSame('permission_missing', $accounts['act_1']['structure']['refusal']['kind']);
        $this->assertSame(['asked' => 4, 'resolved' => 1], $accounts['act_1']['structure']['media']);
        $this->assertNull($accounts['act_2']['structure']['refusal']);
        $this->assertSame(0, $accounts['act_2']['expired_media']);
        $this->assertSame(2, $this->rows()[0]['expired_media']);
    }

    public function test_refusals_are_counted_by_kind_over_the_last_day(): void
    {
        $connection = $this->connection();
        $this->syncRun($connection, 'act_1', 'failed', 'Limit · code 17');
        $this->syncRun($connection, 'act_1', 'failed', 'Limit · code 4');
        $this->syncRun($connection, 'act_1', 'failed', '(#200) permission · code 200');
        $old = $this->syncRun($connection, 'act_1', 'failed', 'Limit · code 17');
        $old->forceFill(['started_at' => now()->subDays(3)])->save();

        $this->assertSame(['permission_missing' => 1, 'rate_limited' => 2], $this->rows()[0]['refusals_24h']);
    }

    public function test_only_meta_connections_are_listed_and_no_call_is_made(): void
    {
        $this->connection();
        app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'tiktok', tokens: new OAuthTokens(self::TOKEN, 'RT', now()->addDay()), connectionName: 'tiktok');

        $this->assertCount(1, $this->rows());
        Http::assertNothingSent();
    }

    public function test_no_token_reaches_the_response_or_the_command_and_a_stored_token_is_redacted(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['last_error' => 'Call failed for https://graph.facebook.com/x?access_token='.self::TOKEN.' · code 190'])->save();

        $body = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/admin/integrations/meta/diagnosis')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::TOKEN, (string) $body);

        Artisan::call('integrations:meta-diagnose');
        $this->assertStringNotContainsString(self::TOKEN, Artisan::output());
    }

    public function test_the_command_prints_codes_and_no_names(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['status' => 'error', 'last_error' => 'Session · code 190 · subcode 460'])->save();
        $this->account($connection, 'act_1', 'Secret Client Name');

        $this->assertSame(0, Artisan::call('integrations:meta-diagnose'));
        $output = Artisan::output();

        $this->assertStringContainsString('needs_reconnect', $output);
        $this->assertStringContainsString('code 190', $output);
        $this->assertStringContainsString('act_1', $output);
        $this->assertStringNotContainsString('Secret Client Name', $output);
        $this->assertStringNotContainsString('Tenant', $output);
    }

    public function test_it_changes_nothing(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['status' => 'error', 'last_error' => 'Session · code 190'])->save();
        $before = $connection->fresh()?->getAttributes();

        $this->rows();
        Artisan::call('integrations:meta-diagnose');

        $this->assertSame($before, $connection->fresh()?->getAttributes());
    }

    public function test_a_tenant_user_cannot_read_it(): void
    {
        $user = User::create(['name' => 'Outsider', 'email' => 'outsider@test.dev', 'password' => 'secret123', 'email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/integrations/meta/diagnosis')->assertForbidden();
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/integrations/meta/diagnosis')
            ->assertOk()
            ->json('data.connections');
    }

    private function owner(): User
    {
        $owner = User::query()->where('email', 'owner@test.dev')->first();
        if ($owner !== null) {
            return $owner;
        }

        $owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev', 'password' => 'secret123', 'email_verified_at' => now()]);
        $owner->forceFill(['is_platform_admin' => true])->save();

        return $owner;
    }

    private function connection(string $name = 'meta'): ProviderConnection
    {
        // One connection per tenant and provider, so a second named connection is a second tenant's.
        $tenant = $name === 'meta' ? $this->tenant : Tenant::create(['name' => $name, 'slug' => $name.'-'.uniqid(), 'status' => 'active']);

        return app(TokenVault::class)->open(tenantId: $tenant->id, provider: 'meta', tokens: new OAuthTokens(self::TOKEN, 'RT', now()->addDays(30)), connectionName: $name);
    }

    private function account(ProviderConnection $connection, string $externalId, ?string $name = null): ExternalAccount
    {
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $name ?? $externalId, 'status' => 'active', 'discovered_at' => now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true, 'campaign_management_enabled' => true,
        ]);

        return $account;
    }

    private function creatives(ExternalAccount $account, int $count, int $expired): void
    {
        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'external_id' => 'c-'.$account->external_id, 'name' => 'C', 'status' => 'active',
        ]);

        for ($i = 0; $i < $count; $i++) {
            ExternalCreative::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $campaign->id,
                'provider' => 'meta', 'external_creative_id' => $account->external_id.'-'.$i, 'name' => 'N', 'source_type' => 'api', 'is_demo' => false,
                'asset_expires_at' => $i < $expired ? now()->subDay() : now()->addDay(),
            ]);
        }
    }

    /** @param array<string,mixed> $meta */
    private function syncRun(ProviderConnection $connection, string $externalId, string $status, ?string $error, array $meta = []): IntegrationSyncRun
    {
        $run = new IntegrationSyncRun;
        $run->forceFill([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'type' => 'structure', 'status' => $status, 'error' => $error,
            'meta' => ['external_account_id' => $externalId, ...$meta], 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4),
        ])->save();

        return $run;
    }
}
