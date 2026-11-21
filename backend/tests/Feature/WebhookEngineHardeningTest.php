<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\PendingStoreInstall;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Webhooks\WebhookEventProcessor;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * INTEG-WEBHOOK-ENGINE-001 — the webhook engine's eight guarantees, each through the public endpoint.
 *
 * The provider is Salla (signed body with a time inside it, store orders, two stores per merchant). Every
 * provider call is `Http::fake`d and stray requests are refused: nothing here reaches a real platform.
 */
final class WebhookEngineHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'salla-webhook-secret';

    private Tenant $tenant;

    private Project $project;

    private ClientWorkspace $workspace;

    private ExternalAccount $storeA;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PlatformCredentials::for('salla')->requires() as $key) {
            config()->set("commerce_platforms.platforms.salla.{$key}", "test-{$key}");
        }

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProviderConfigurationService::class)->save('salla', [
            'client_id' => 'app-1', 'client_secret' => 'salla-secret', 'webhook_secret' => self::SECRET,
        ]);

        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Retainer', 'status' => 'active',
        ]);

        $this->storeA = $this->store('store-A', bound: true);
        app(TenantContext::class)->forget();

        Http::preventStrayRequests();
    }

    // ── (1) the inbox records every delivery, with its verdict ──────────────────────────────────

    public function test_a_refused_delivery_is_recorded_with_its_verdict_and_never_its_body_or_signature(): void
    {
        Queue::fake();
        $body = $this->body('order.updated', 'o1', 'processing', 'evt-secret-body-marker');

        $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SALLA_SIGNATURE' => 'forged-signature-marker',
        ], $body)->assertStatus(401);

        $row = IntegrationWebhookEvent::query()->firstOrFail();
        $this->assertSame('rejected', $row->status);
        $this->assertSame('rejected_signature', $row->verdict);
        $this->assertFalse($row->signature_verified);
        $this->assertNull($row->payload);
        $this->assertNotEmpty($row->correlation_id);
        $this->assertNotNull($row->received_at);

        $dump = json_encode($row->getAttributes());
        $this->assertStringNotContainsString('forged-signature-marker', (string) $dump);
        $this->assertStringNotContainsString('evt-secret-body-marker', (string) $dump);
        Queue::assertNothingPushed();
    }

    public function test_a_refused_delivery_cannot_poison_the_genuine_delivery_of_the_same_event_id(): void
    {
        Queue::fake();
        $payload = $this->payload('order.updated', 'o1', 'processing', 'evt-guessed-id');
        $raw = (string) json_encode($payload);

        // An attacker who guessed the event id posts it with a bad signature first …
        $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SALLA_SIGNATURE' => 'bad',
        ], $raw)->assertStatus(401);

        // … and the genuine one still lands as a first delivery, not a «duplicate».
        $this->sign($payload)->assertOk();

        $genuine = IntegrationWebhookEvent::query()->where('status', '!=', 'rejected')->firstOrFail();
        $this->assertSame(0, $genuine->duplicate_count);
        $this->assertSame('verified', $genuine->verdict);
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }

    // ── (1b) no credential is kept in the inbox ────────────────────────────────────────────────

    /**
     * Defect 138: Salla's `app.store.authorize` carries the merchant's access and refresh tokens in its body,
     * and the whole body was stored in the inbox. The handler still gets them (in memory, into the vault's
     * pending install); the inbox row does not. An install for a merchant with no store here yet is
     * `unmatched` and must still be handled; and a handler that fails is retried by the provider's redelivery.
     */
    public function test_a_lifecycle_delivery_hands_over_its_tokens_but_the_inbox_never_keeps_them(): void
    {
        $fail = true;
        Http::fake(function () use (&$fail) {
            if ($fail) {
                throw new ConnectionException('provider unreachable');
            }

            return Http::response(['data' => ['id' => 'brand-new', 'name' => 'Brand new']]);
        });
        $payload = $this->payload('app.store.authorize', 'x', 'x', 'evt-auth', merchant: 'brand-new');
        $payload['data'] = ['access_token' => 'ACCESS-TOKEN-MARKER', 'refresh_token' => 'REFRESH-TOKEN-MARKER', 'expires' => time() + 3600];

        // The handler fails (Salla unreachable): 500, the row says failed, and no token is in it.
        $this->sign($payload)->assertStatus(500);
        $row = IntegrationWebhookEvent::query()->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertNotEmpty($row->error);
        $this->assertStringNotContainsString('TOKEN-MARKER', (string) json_encode($row->getAttributes()));

        // The provider retries; the failed handler runs again and this time hands the tokens over.
        $fail = false;
        $this->sign($payload)->assertOk();
        $row->refresh();
        $this->assertSame('processed', $row->status);
        $this->assertSame(1, PendingStoreInstall::query()->where('merchant_id', 'brand-new')->count());
        $this->assertStringNotContainsString('TOKEN-MARKER', (string) json_encode($row->getAttributes()));
        $this->assertSame('[redacted]', $row->payload['data']['access_token']);
    }

    // ── (2) replay protection ──────────────────────────────────────────────────────────────────

    public function test_a_validly_signed_delivery_older_than_the_window_is_rejected_and_recorded(): void
    {
        Queue::fake();
        $old = $this->payload('order.updated', 'o1', 'processing', 'evt-old', Carbon::now()->subDays(3));

        $this->sign($old)->assertStatus(400);

        $row = IntegrationWebhookEvent::query()->firstOrFail();
        $this->assertSame('rejected', $row->status);
        $this->assertSame('rejected_stale', $row->verdict);
        $this->assertTrue($row->signature_verified, 'the signature was good: the refusal is about age');
        $this->assertNull($row->payload);
        Queue::assertNothingPushed();
    }

    public function test_a_delivery_stamped_in_the_future_is_rejected_and_one_inside_the_window_is_accepted(): void
    {
        Queue::fake();

        $this->sign($this->payload('order.updated', 'o1', 'processing', 'evt-future', Carbon::now()->addHours(2)))->assertStatus(400);
        $this->sign($this->payload('order.updated', 'o1', 'processing', 'evt-fresh', Carbon::now()->subHours(20)))->assertOk();

        $this->assertSame(['verified', 'rejected_stale'], IntegrationWebhookEvent::query()->orderBy('verdict', 'desc')->pluck('verdict')->all());
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }

    /** Salla's `created_at` may be the store's local wall clock with no zone: a Riyadh stamp read as UTC is three hours «ahead». */
    public function test_a_zoneless_timestamp_a_few_hours_ahead_is_a_local_clock_not_a_future_delivery(): void
    {
        Queue::fake();
        $payload = $this->payload('order.updated', 'o1', 'processing', 'evt-local-clock');
        $payload['created_at'] = Carbon::now('UTC')->addHours(3)->format('Y-m-d H:i:s');

        $this->sign($payload)->assertOk();
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }

    // ── (3) dedup / idempotency ────────────────────────────────────────────────────────────────

    public function test_the_same_event_twice_has_one_effect_and_the_second_is_recorded_as_a_duplicate(): void
    {
        $this->fakeStore('store-A', [['o1', 'completed']]);
        $payload = $this->payload('order.created', 'o1', 'completed', 'evt-once');

        $this->sign($payload)->assertOk();
        $this->sign($payload)->assertOk(); // answered 2xx: the provider must stop retrying

        $this->assertSame(1, IntegrationWebhookEvent::query()->count());
        $event = IntegrationWebhookEvent::query()->firstOrFail();
        $this->assertSame('processed', $event->status);
        $this->assertSame(1, $event->duplicate_count);
        $this->assertNotNull($event->last_duplicate_at);
        $this->assertSame(1, CommerceOrder::withoutGlobalScopes()->count(), 'no duplicated order');
        $this->assertSame(1, $this->ordersCalls(), 'the store was read once');
    }

    /** A busy store sends dozens of events a minute: a read that STARTED after an event arrived already covers it. */
    public function test_a_burst_of_different_events_for_one_store_is_one_read_not_one_per_event(): void
    {
        Queue::fake();
        $this->fakeStore('store-A', [['o1', 'processing'], ['o1', 'completed']]);

        $this->sign($this->payload('order.created', 'o1', 'processing', 'evt-b1'))->assertOk();
        $this->sign($this->payload('order.updated', 'o2', 'processing', 'evt-b2', Carbon::now()->subSeconds(60)))->assertOk();

        foreach (IntegrationWebhookEvent::query()->orderBy('received_at')->pluck('id') as $id) {
            (new ProcessWebhookEventJob((string) $id))->handle(app(WebhookEventProcessor::class));
        }

        $this->assertSame(1, $this->ordersCalls());
        $this->assertSame(['processed', 'processed'], IntegrationWebhookEvent::query()->orderBy('received_at')->pluck('status')->all());
    }

    // ── (4) out of order ───────────────────────────────────────────────────────────────────────

    /** The provider answers the late event with a STALE cached order: only the ordering rule keeps «completed». */
    public function test_a_late_order_updated_never_overwrites_a_newer_order_completed(): void
    {
        $this->fakeStore('store-A', [['o1', 'completed'], ['o1', 'processing']]);

        $this->sign($this->payload('order.completed', 'o1', 'completed', 'evt-new', Carbon::now()->subMinutes(5)))->assertOk();
        $this->assertSame('completed', CommerceOrder::withoutGlobalScopes()->firstOrFail()->status);

        // Older than the one above, arriving after it, answered with an out-of-date state by the provider.
        $this->sign($this->payload('order.updated', 'o1', 'processing', 'evt-late', Carbon::now()->subMinutes(30)))->assertOk();

        $this->assertSame('completed', CommerceOrder::withoutGlobalScopes()->firstOrFail()->status);
        $late = IntegrationWebhookEvent::query()->where('external_event_id', 'evt-late')->firstOrFail();
        $this->assertSame('superseded', $late->status);
        $this->assertSame(1, $this->ordersCalls(), 'the late event fetched nothing');
    }

    // ── (5) 200 is not «confirmed» ─────────────────────────────────────────────────────────────

    public function test_answering_200_leaves_the_delivery_queued_not_processed(): void
    {
        Queue::fake();

        $this->sign($this->payload('order.created', 'o1', 'completed', 'evt-q'))->assertOk();

        $this->assertSame('queued', IntegrationWebhookEvent::query()->firstOrFail()->status);
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }

    public function test_a_failing_sync_is_retried_within_a_bound_then_dead_lettered_never_silent(): void
    {
        Queue::fake();
        Http::fake(['api.salla.dev/*' => Http::response(['error' => 'down'], 500)]);
        $this->sign($this->payload('order.created', 'o1', 'completed', 'evt-fail'))->assertOk();
        $event = IntegrationWebhookEvent::query()->firstOrFail();

        $job = new ProcessWebhookEventJob((string) $event->getKey());

        // The queue's contract: bounded attempts, growing wait.
        $this->assertSame(5, $job->tries);
        $this->assertSame([30, 120, 600, 1800], $job->backoff());

        // Attempt 1 fails: the row says so and the exception makes the queue retry.
        try {
            $job->handle(app(WebhookEventProcessor::class));
            $this->fail('a failed sync must throw so the queue retries it');
        } catch (\RuntimeException $e) {
            $event->refresh();
            $this->assertSame('failed', $event->status);
            $this->assertSame(1, $event->attempts);
            $this->assertNotEmpty($event->error);
        }

        // The last attempt fails: the queue calls failed() and the row ends in the dead-letter state.
        $job->failed($e);
        $event->refresh();
        $this->assertSame('dead_letter', $event->status);
        $this->assertNotNull($event->dead_lettered_at);
    }

    // ── (6) scoping at ingest ──────────────────────────────────────────────────────────────────

    public function test_of_two_stores_of_one_merchant_only_the_bound_ones_order_lands(): void
    {
        $this->store('store-B', bound: false);

        $this->fakeStore('store-A', [['oA', 'completed']]);

        // The event for the unbound store changes nothing and makes no provider call.
        $this->sign($this->payload('order.created', 'oB', 'completed', 'evt-B', merchant: 'store-B'))->assertOk();
        $unbound = IntegrationWebhookEvent::query()->where('external_event_id', 'evt-B')->firstOrFail();
        $this->assertSame('ignored_unbound', $unbound->status);
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->count());
        Http::assertNothingSent();

        // The bound one's lands.
        $this->sign($this->payload('order.created', 'oA', 'completed', 'evt-A', merchant: 'store-A'))->assertOk();
        $this->assertSame(['oA'], CommerceOrder::withoutGlobalScopes()->pluck('external_id')->all());
        $this->assertSame($this->storeA->getKey(), CommerceOrder::withoutGlobalScopes()->firstOrFail()->external_account_id);
    }

    // ── (7) admin-only, audited replay ─────────────────────────────────────────────────────────

    public function test_replay_re_runs_a_dead_lettered_delivery_once_and_is_audited(): void
    {
        $event = $this->deadLetter();
        $admin = $this->admin();

        $this->fakeStore('store-A', [['o1', 'completed']]);

        $this->actingAs($admin)->postJson("/api/v1/admin/webhook-events/{$event->getKey()}/replay")
            ->assertOk()->assertJsonPath('data.outcome', 'requeued');
        Queue::assertPushed(ProcessWebhookEventJob::class, 2); // the original delivery's, and the replay's
        $this->assertSame('queued', $event->fresh()->status);

        // The worker picks it up: the same handler, now succeeding.
        (new ProcessWebhookEventJob((string) $event->getKey()))->handle(app(WebhookEventProcessor::class));

        $event->refresh();
        $this->assertSame('processed', $event->status, 'the same handler ran and this time succeeded');
        $this->assertSame(1, $event->replayed_count);
        $this->assertSame(1, CommerceOrder::withoutGlobalScopes()->count());

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'webhook.event.replayed')->firstOrFail();
        $this->assertSame((string) $event->getKey(), $audit->entity_id);
        $this->assertSame($admin->id, $audit->user_id);

        // A second replay of a processed delivery is a no-op: nothing re-read, nothing counted.
        $this->actingAs($admin)->postJson("/api/v1/admin/webhook-events/{$event->getKey()}/replay")
            ->assertOk()->assertJsonPath('data.outcome', 'noop');
        $this->assertSame(1, $event->fresh()->replayed_count);
        $this->assertSame(1, $this->ordersCalls());
    }

    public function test_replay_and_the_inbox_are_closed_to_everyone_but_the_platform_owner(): void
    {
        $event = $this->deadLetter();
        $member = User::create(['name' => 'Member', 'email' => 'm@acme.test', 'password' => 'secret123', 'email_verified_at' => now()]);

        $this->postJson("/api/v1/admin/webhook-events/{$event->getKey()}/replay")->assertUnauthorized();
        $this->actingAs($member)->postJson("/api/v1/admin/webhook-events/{$event->getKey()}/replay")->assertForbidden();
        $this->actingAs($member)->getJson('/api/v1/admin/webhook-events')->assertForbidden();
        $this->assertSame('dead_letter', $event->fresh()->status);

        // And the owner's list shows the verdict and state, never the body.
        $row = $this->actingAs($this->admin())->getJson('/api/v1/admin/webhook-events')->assertOk()->json('data.0');
        $this->assertSame('dead_letter', $row['status']);
        $this->assertSame('verified', $row['verdict']);
        $this->assertArrayNotHasKey('payload', $row);
    }

    // ── (8) reconciliation ─────────────────────────────────────────────────────────────────────

    public function test_reconciliation_records_the_gap_a_webhook_never_closed_and_reads_only_bound_stores(): void
    {
        $this->store('store-B', bound: false);
        $placed = Carbon::now()->subDays(2)->format('Y-m-d H:i:s.000000');
        Http::fake([
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => 'store-A', 'name' => 'A', 'currency' => 'SAR']]),
            'api.salla.dev/*/orders*' => Http::response(['data' => [[
                'id' => 'o-missing', 'reference_id' => 7, 'status' => ['slug' => 'completed'],
                'date' => ['date' => $placed, 'timezone' => 'Asia/Riyadh'],
                'amounts' => ['total' => ['amount' => 50, 'currency' => 'SAR']], 'items' => [],
            ]], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
        ]);

        // Dry run: reports, records nothing.
        Artisan::call('webhooks:reconcile', ['--dry-run' => true]);
        $this->assertSame(0, IntegrationWebhookEvent::query()->where('source', 'reconciliation')->count());
        $this->assertStringContainsString('missing_local order o-missing', Artisan::output());

        // Real run: one gap row, and a repeat adds no second row.
        Artisan::call('webhooks:reconcile');
        Artisan::call('webhooks:reconcile');
        $gap = IntegrationWebhookEvent::query()->where('source', 'reconciliation')->firstOrFail();
        $this->assertSame('gap', $gap->status);
        $this->assertSame('reconciliation.missing_local', $gap->topic);
        $this->assertFalse($gap->payload['inbox_seen'], 'no webhook about this order ever arrived');
        $this->assertSame(1, $gap->duplicate_count);
        $this->assertSame($this->storeA->getKey(), $gap->external_account_id);

        // Read-only toward the provider; the unbound store was never asked; nothing was written to the orders table.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET');
        Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->count());
        // Three runs; each asked ONE store who it is, so the unbound store was never read at all.
        $this->assertSame(3, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/store/info'))->count());
        $this->assertSame(3, $this->ordersCalls());
    }

    public function test_the_reconciliation_is_scheduled_but_stays_off_until_the_flag_is_set(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'webhooks:reconcile'));
        $this->assertNotNull($event, 'the command is listed in the scheduler');

        config()->set('integrations.webhooks.reconcile.enabled', false);
        $this->assertFalse($event->filtersPass(app()));

        config()->set('integrations.webhooks.reconcile.enabled', true);
        $this->assertTrue($event->filtersPass(app()));
    }

    // ── fixtures ───────────────────────────────────────────────────────────────────────────────

    private function store(string $externalId, bool $bound): ExternalAccount
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: 'salla-merchant',
        );

        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id,
            'provider_connection_id' => $connection->getKey(), 'provider' => 'salla',
            'account_type' => 'store', 'external_id' => $externalId, 'name' => $externalId, 'currency' => 'SAR', 'status' => 'active',
        ]);

        if ($bound) {
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
                'external_account_id' => $store->getKey(), 'provider' => 'salla', 'purpose' => 'ecommerce', 'is_active' => true,
            ]);
        }

        return $store;
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    /** A delivery whose sync failed every time, as the queue would leave it. */
    private function deadLetter(): IntegrationWebhookEvent
    {
        Queue::fake();
        $this->sign($this->payload('order.created', 'o1', 'completed', 'evt-dead'))->assertOk();
        $event = IntegrationWebhookEvent::query()->firstOrFail();
        app(WebhookEventProcessor::class)->deadLetter((string) $event->getKey(), 'The sync failed: provider down.');
        // The worker that dead-letters a job releases its unique lock; the fake queue never ran one.
        Cache::lock('laravel_unique_job:'.ProcessWebhookEventJob::class.':webhook-event:'.$event->getKey())->forceRelease();

        return $event->fresh();
    }

    /** @return array<string,mixed> */
    private function payload(string $topic, string $orderId, string $status, string $eventId, ?Carbon $at = null, string $merchant = 'store-A'): array
    {
        return [
            'event' => $topic, 'merchant' => $merchant, 'event_id' => $eventId,
            'created_at' => ($at ?? Carbon::now())->toIso8601String(),
            'data' => ['id' => $orderId, 'status' => ['slug' => $status]],
        ];
    }

    private function body(string $topic, string $orderId, string $status, string $eventId): string
    {
        return (string) json_encode($this->payload($topic, $orderId, $status, $eventId));
    }

    /** @param array<string,mixed> $payload */
    private function sign(array $payload): TestResponse
    {
        $raw = (string) json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $raw, self::SECRET),
        ], $raw);
    }

    /**
     * What Salla answers for one store: its identity, and its orders one answer per orders read, in order.
     *
     * @param  list<array{0:string,1:string}>  $orders  [order id, status slug] per successive read
     */
    private function fakeStore(string $storeId, array $orders): void
    {
        $sequence = Http::sequence();
        foreach ($orders as [$id, $status]) {
            $sequence->push(['data' => [[
                'id' => $id, 'reference_id' => 1, 'status' => ['slug' => $status],
                'date' => ['date' => Carbon::now()->subDays(1)->format('Y-m-d H:i:s.000000'), 'timezone' => 'Asia/Riyadh'],
                'amounts' => ['total' => ['amount' => 100, 'currency' => 'SAR']], 'items' => [],
            ]], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]);
        }
        $sequence->whenEmpty(Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]));

        Http::fake([
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => $storeId, 'name' => $storeId, 'currency' => 'SAR']]),
            'api.salla.dev/*/products*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/customers*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/orders*' => $sequence,
            'api.salla.dev/*/carts/abandoned*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
        ]);
    }

    private function ordersCalls(): int
    {
        return Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/orders'))->count();
    }
}
