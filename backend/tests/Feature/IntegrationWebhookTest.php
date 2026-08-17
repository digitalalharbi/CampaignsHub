<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * WEBHOOK-001 — the public endpoint providers push to.
 *
 * Almost every test here is a REFUSAL. That is the shape of the surface: it is unauthenticated, it is
 * on the open internet, and the only thing standing between it and a customer's funnel is an HMAC.
 */
final class IntegrationWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret-for-signing';

    private Tenant $tenant;

    private ExternalAccount $account;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        app(ProviderConfigurationService::class)->save('meta', [
            'client_id' => 'app-1',
            'client_secret' => self::SECRET,
            'webhook_verify_token' => 'the-verify-token',
        ]);

        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'meta',
            'credential_scope' => 'tenant',
            'credential_type' => 'oauth',
            'encrypted_payload' => json_encode(['access_token' => 'tok']),
            'status' => 'active',
        ]);

        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->getKey(),
            'provider' => 'meta',
            'connection_name' => 'Meta',
            'status' => 'connected',
        ]);

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'external_id' => 'act_123456',
            'account_type' => 'ad_account',
            'name' => 'Acme ads',
        ]);

        /*
         * Assigned to a project — RUNTIME-100 §11.
         *
         * A delivery for an account nobody attached to a project now queues nothing: the jobs would
         * refuse it anyway, and a queue full of work that exists only to refuse itself reads as
         * activity on a connection that is doing nothing. These tests are about the VERIFICATION and
         * the dispatch that follows it, so the fixture has to satisfy the gate in front of them.
         */
        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id,
            'name' => 'Retainer', 'status' => 'active',
        ]);
        $this->assign($this->account);

        app(TenantContext::class)->forget();
        Queue::fake();
    }

    /** The explicit decision every sync path now reads before it fetches anything. */
    private function assign(ExternalAccount $account, string $purpose = 'advertising'): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(),
            'provider' => $account->provider,
            'purpose' => $purpose,
            'is_active' => true,
        ]);
    }

    // ── refusals ──────────────────────────────────────────────────────────────────────────────

    /** The one that matters most: an unsigned body reaches nothing and is stored nowhere. */
    public function test_an_unsigned_delivery_is_refused_and_nothing_is_recorded(): void
    {
        $this->postJson('/api/v1/webhooks/ads/meta', ['entry' => [['id' => 'act_123456']]])
            ->assertStatus(401);

        $this->assertDatabaseCount('integration_webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_wrongly_signed_delivery_is_refused(): void
    {
        $body = json_encode(['entry' => [['id' => 'act_123456']], 'event_id' => 'e-1']);

        $this->call('POST', '/api/v1/webhooks/ads/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-wrong-secret'),
        ], $body)->assertStatus(401);

        $this->assertDatabaseCount('integration_webhook_events', 0);
    }

    /**
     * No secret configured means REFUSED, never accepted.
     *
     * The tempting default — let it through until somebody finishes the setup — turns an unfinished
     * configuration into an open endpoint writing whatever anybody posts into a customer's funnel.
     */
    public function test_a_provider_with_no_secret_configured_refuses_everything(): void
    {
        $body = json_encode(['event' => 'order.created', 'merchant' => '9', 'event_id' => 'e-2']);

        $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $body, 'anything'),
        ], $body)->assertStatus(401);

        $this->assertDatabaseCount('integration_webhook_events', 0);
    }

    /** A provider that does not deliver webhooks has no endpoint, rather than one that always refuses. */
    public function test_a_polling_only_provider_has_no_endpoint(): void
    {
        $this->postJson('/api/v1/webhooks/ads/google', [])->assertStatus(404);
        $this->postJson('/api/v1/webhooks/ads/snapchat', [])->assertStatus(404);
    }

    /** The two families keep separate paths; a commerce provider is not reachable as an advertising one. */
    public function test_a_provider_is_not_reachable_under_the_wrong_kind(): void
    {
        $this->postJson('/api/v1/webhooks/ads/salla', [])->assertStatus(404);
        $this->postJson('/api/v1/webhooks/commerce/meta', [])->assertStatus(404);
    }

    /** A suspended provider stops being LISTENED to, not merely stops being connectable. */
    public function test_a_disabled_provider_stops_accepting_deliveries(): void
    {
        app(ProviderConfigurationService::class)->setEnabled('meta', false);

        $this->signed(['entry' => [['id' => 'act_123456']], 'event_id' => 'e-3'])->assertStatus(403);

        $this->assertDatabaseCount('integration_webhook_events', 0);
    }

    // ── the happy path ────────────────────────────────────────────────────────────────────────

    public function test_a_verified_delivery_is_recorded_placed_and_triggers_the_same_sync_the_scheduler_runs(): void
    {
        $this->signed(['entry' => [['id' => 'act_123456']], 'event_id' => 'evt-100'])->assertOk();

        $event = IntegrationWebhookEvent::query()->firstOrFail();

        $this->assertTrue($event->signature_verified);
        $this->assertSame('processed', $event->status);
        // Derived from the account we already hold — never read out of the payload.
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertSame($this->account->getKey(), $event->external_account_id);

        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
    }

    /**
     * Idempotency, which is the whole reason the unique index exists.
     *
     * Meta redelivers for 36 hours until it gets a 2xx. A receiver that worked on every delivery
     * would re-sync — and, for a commerce consumer, re-count a purchase — every single time our own
     * response was slow.
     */
    public function test_a_redelivery_is_recorded_once_worked_once_and_still_answered_with_a_success(): void
    {
        $payload = ['entry' => [['id' => 'act_123456']], 'event_id' => 'evt-repeat'];

        $this->signed($payload)->assertOk();
        $this->signed($payload)->assertOk();
        $this->signed($payload)->assertOk();

        $this->assertDatabaseCount('integration_webhook_events', 1);
        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
    }

    /** Two genuinely different events are two events, even without an event id to tell them apart. */
    public function test_two_different_bodies_without_event_ids_are_two_events(): void
    {
        $this->signed(['entry' => [['id' => 'act_123456']], 'time' => 1])->assertOk();
        $this->signed(['entry' => [['id' => 'act_123456']], 'time' => 2])->assertOk();

        $this->assertDatabaseCount('integration_webhook_events', 2);
    }

    /**
     * A delivery for an account nobody here has connected is KEPT, not dropped.
     *
     * It is the evidence that a webhook URL was registered against the wrong app — a real
     * misconfiguration that is completely invisible if the payload is discarded.
     */
    public function test_a_delivery_that_matches_no_account_is_kept_as_unmatched_and_starts_no_work(): void
    {
        $this->signed(['entry' => [['id' => 'act_somebody_else']], 'event_id' => 'evt-x'])->assertOk();

        $event = IntegrationWebhookEvent::query()->firstOrFail();

        $this->assertSame('unmatched', $event->status);
        $this->assertNull($event->tenant_id);
        Queue::assertNothingPushed();
    }

    // ── A commerce delivery drives the store sync, not a direct write ─────────────────────────

    /**
     * COMMERCE-001 — a verified Salla delivery triggers the SAME store sync the scheduler runs.
     *
     * It deliberately does not write the order the payload carries. An order changes for a fortnight
     * after it is placed — paid, shipped, returned, refunded — and each change is a separate delivery
     * that can arrive out of order or not at all. A receiver that applied each payload as it landed
     * would write «قيد المعالجة» over «مكتمل» whenever two crossed, and a refund that never arrived
     * would leave revenue on a client's report for ever.
     *
     * The window is wider than the advertising one, because a store event is frequently about an
     * OLDER order: a refund on a three-week-old purchase is a delivery today.
     */
    public function test_a_verified_store_delivery_triggers_the_store_sync_over_a_wide_window(): void
    {
        app(ProviderConfigurationService::class)->save('salla', [
            'client_id' => 'app-1',
            'client_secret' => 'salla-secret',
            'webhook_secret' => self::SECRET,
        ]);

        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider' => 'salla', 'credential_scope' => 'tenant',
            'credential_type' => 'oauth', 'encrypted_payload' => json_encode(['access_token' => 'tok']),
            'status' => 'active',
        ]);

        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->getKey(),
            'provider' => 'salla', 'connection_name' => 'Salla', 'status' => 'connected',
        ]);

        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'external_id' => '778899', 'account_type' => 'store', 'name' => 'متجر',
        ]);
        $this->assign($store, 'ecommerce');

        $body = json_encode(['event' => 'order.updated', 'merchant' => '778899', 'event_id' => 'salla-evt-1']);

        $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertOk();

        $event = IntegrationWebhookEvent::query()->where('provider', 'salla')->firstOrFail();
        $this->assertSame('processed', $event->status);
        $this->assertSame('order.updated', $event->topic);
        $this->assertSame($store->getKey(), $event->external_account_id);

        Queue::assertPushed(SyncStoreJob::class, 1);
        // And NOT the advertising job — the two families do not share a consumer.
        Queue::assertNotPushed(SyncAccountMetricsJob::class);
    }

    // ── Meta's subscription handshake ─────────────────────────────────────────────────────────

    public function test_the_subscription_handshake_echoes_the_challenge_only_for_the_configured_token(): void
    {
        $this->get('/api/v1/webhooks/ads/meta?hub_verify_token=the-verify-token&hub_challenge=1234567')
            ->assertOk()
            ->assertSee('1234567');

        $this->get('/api/v1/webhooks/ads/meta?hub_verify_token=guessed&hub_challenge=1234567')
            ->assertStatus(403);
    }

    /** @param array<string,mixed> $payload */
    private function signed(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/ads/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }
}
