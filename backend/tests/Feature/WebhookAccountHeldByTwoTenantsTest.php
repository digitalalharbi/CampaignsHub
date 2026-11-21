<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Webhooks\WebhookEventProcessor;
use App\Domains\Integrations\Webhooks\WebhookSyncRunner;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — a provider account held by two tenants is one delivery for both.
 *
 * ## The boundary question
 *
 * A provider delivers ONE webhook per app for an event on an ad account. Nothing stops two of our
 * tenants — an agency and the advertiser it works for, say — each authorising that same account
 * under their own connection, and then `external_accounts` holds the same `(provider, external_id)`
 * under two tenant ids.
 *
 * `WebhookIngest::record()` resolved the delivery with `->where('external_id')->where('provider')
 * ->first()` — across every tenant, in whatever order Postgres found the rows. So the event was filed
 * under an arbitrary one of the two tenants, and only THAT tenant's account was handed the sync the
 * delivery exists to trigger. The other tenant's account got nothing until the half-hourly sweep.
 *
 * Two things must hold instead. Every tenant's actively-assigned copy of the account gets its sync,
 * each through its OWN connection and its OWN assignment — a job re-proves both before it fetches, so
 * no row can cross a tenant through this path. And the event row must not CLAIM one tenant when two
 * hold the account: attributing a delivery to whichever row came first is a guess recorded as a fact.
 *
 * What this is NOT: no metric, structure or lead row was ever written across a tenant by the old
 * resolution — the dispatched job fetches through the chosen account's connection into that
 * account's own project — and `integration_webhook_events` is read by no tenant-facing surface. The
 * defect is a lost trigger and a mis-attributed audit row, and it is fixed at the resolution.
 */
final class WebhookAccountHeldByTwoTenantsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret-for-signing';

    private const SHARED_ACCOUNT = 'act_777000';

    private ExternalAccount $agencyCopy;

    private ExternalAccount $advertiserCopy;

    protected function setUp(): void
    {
        parent::setUp();

        app(ProviderConfigurationService::class)->save('meta', [
            'client_id' => 'app-1',
            'client_secret' => self::SECRET,
            'webhook_verify_token' => 'the-verify-token',
        ]);

        $this->agencyCopy = $this->tenantHolding('agency', self::SHARED_ACCOUNT);
        $this->advertiserCopy = $this->tenantHolding('advertiser', self::SHARED_ACCOUNT);

        app(TenantContext::class)->forget();
        Queue::fake();
    }

    public function test_a_delivery_for_an_account_held_by_two_tenants_triggers_both_of_their_syncs(): void
    {
        $this->signed(['entry' => [['id' => self::SHARED_ACCOUNT]], 'event_id' => 'evt-shared-1'])->assertOk();

        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
        $dispatched = $this->drain();

        $expected = [$this->agencyCopy->getKey(), $this->advertiserCopy->getKey()];
        sort($expected);

        $this->assertSame($expected, $dispatched, 'each tenant holding the account must get its own sync, through its own connection');
    }

    public function test_the_event_row_claims_no_single_tenant_when_two_hold_the_account(): void
    {
        $this->signed(['entry' => [['id' => self::SHARED_ACCOUNT]], 'event_id' => 'evt-shared-2'])->assertOk();

        $event = IntegrationWebhookEvent::query()->firstOrFail();

        // INTEG-WEBHOOK-ENGINE-001 — a 200 means recorded; «processed» is the worker's word, after the syncs ran.
        $this->assertSame('queued', $event->status);
        $this->assertNull($event->tenant_id, 'a delivery two tenants are entitled to must not be filed under one of them');
        $this->assertNull($event->external_account_id);
    }

    public function test_a_tenant_whose_copy_is_not_assigned_gets_no_sync_while_the_other_still_does(): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $this->advertiserCopy->getKey())
            ->update(['is_active' => false]);

        $this->signed(['entry' => [['id' => self::SHARED_ACCOUNT]], 'event_id' => 'evt-shared-3'])->assertOk();

        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
        $this->assertSame([$this->agencyCopy->getKey()], $this->drain());
    }

    public function test_an_account_held_by_one_tenant_is_still_attributed_to_that_tenant(): void
    {
        $only = $this->tenantHolding('solo', 'act_999000');

        $this->signed(['entry' => [['id' => 'act_999000']], 'event_id' => 'evt-solo'])->assertOk();

        $event = IntegrationWebhookEvent::query()->firstOrFail();

        $this->assertSame($only->tenant_id, $event->tenant_id);
        $this->assertSame($only->getKey(), $event->external_account_id);
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
        $this->assertSame([$only->getKey()], $this->drain());
    }

    /**
     * Run the worker for the one recorded delivery with the metrics syncer faked, and say which accounts it synced.
     *
     * @return list<string>
     */
    private function drain(): array
    {
        $synced = [];
        $this->mock(WebhookSyncRunner::class, function ($m) use (&$synced): void {
            $m->shouldReceive('run')->andReturnUsing(function (ExternalAccount $a) use (&$synced): MetricSyncRun {
                $synced[] = (string) $a->getKey();

                return new MetricSyncRun(['status' => 'success']);
            });
        });

        app(WebhookEventProcessor::class)->process((string) IntegrationWebhookEvent::query()->firstOrFail()->getKey());
        sort($synced);

        return $synced;
    }

    // ── fixture ────────────────────────────────────────────────────────────────────────────────────

    /** One tenant, one Meta connection, one discovered copy of the account, actively assigned to a project. */
    private function tenantHolding(string $slug, string $externalId): ExternalAccount
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug.'-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider' => 'meta', 'credential_scope' => 'tenant',
            'credential_type' => 'oauth', 'encrypted_payload' => json_encode(['access_token' => 'tok-'.$slug]), 'status' => 'active',
        ]);

        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->getKey(), 'provider' => 'meta',
            'connection_name' => 'Meta '.$slug, 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'meta',
            'external_id' => $externalId, 'account_type' => 'ad_account', 'name' => $slug.' ads',
        ]);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Client', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $project->id,
            'external_account_id' => $account->getKey(), 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return $account;
    }

    private function signed(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/ads/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }
}
