<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Notification hardening: dedup, delivery states, preference/quiet-hours suppression, read/unread. */
final class NotificationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@a.test', 'password' => 'secret123']);
        $this->dispatcher = app(NotificationDispatcher::class);
    }

    /** @param array<string,mixed> $over */
    private function payload(array $over = []): array
    {
        return array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'type' => 'request_assigned', 'title' => 'Assigned', 'source' => 'requests',
            'entity_type' => 'external_request', 'entity_id' => 'REQ-1',
        ], $over);
    }

    public function test_duplicate_within_window_is_collapsed(): void
    {
        $this->assertNotNull($this->dispatcher->dispatch($this->payload()));
        $this->assertNull($this->dispatcher->dispatch($this->payload())); // same key within window → deduped

        $this->assertSame(1, AppNotification::where('user_id', $this->user->id)->where('type', 'request_assigned')->count());
    }

    public function test_in_app_sent_and_email_awaiting_credentials_by_default(): void
    {
        $n = $this->dispatcher->dispatch($this->payload());
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $n->id, 'channel' => 'in_app', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $n->id, 'channel' => 'email', 'status' => 'awaiting_credentials']);
        // Never "sent" for email without a provider.
        $this->assertDatabaseMissing('notification_deliveries', ['channel' => 'email', 'status' => 'sent']);
    }

    public function test_category_preference_off_suppresses_in_app(): void
    {
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]),
            'categories' => json_encode(['budget' => ['in_app' => false, 'email' => false]]),
            'frequency' => 'realtime', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $n = $this->dispatcher->dispatch($this->payload(['type' => 'budget_risk', 'title' => 'Budget', 'entity_id' => 'C1']));
        $this->assertNull($n); // in-app suppressed by preference
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'in_app', 'status' => 'suppressed_by_preference']);
    }

    public function test_quiet_hours_suppress_email_but_keep_in_app(): void
    {
        // A quiet window that always contains "now" (±30 min; the dispatcher handles midnight wrap).
        $start = now()->copy()->subMinutes(30)->format('H:i');
        $end = now()->copy()->addMinutes(30)->format('H:i');
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]),
            'categories' => json_encode([]),
            'quiet_hours' => json_encode(['enabled' => true, 'start' => $start, 'end' => $end]),
            'frequency' => 'realtime', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $n = $this->dispatcher->dispatch($this->payload(['entity_id' => 'REQ-QH']));
        $this->assertNotNull($n); // in-app inbox still receives it
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $n->id, 'channel' => 'email', 'status' => 'suppressed_by_quiet_hours']);
    }

    public function test_read_unread_via_api(): void
    {
        $n = $this->dispatcher->dispatch($this->payload());

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonPath('meta.unread', 1);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/notifications/{$n->id}/read")->assertOk();
        $this->assertDatabaseHas('app_notifications', ['id' => $n->id, 'status' => 'read']);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 0);
    }

    public function test_delivery_log_endpoint_lists_channel_states(): void
    {
        $this->dispatcher->dispatch($this->payload());
        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/notifications/deliveries')->assertOk();
        $this->assertGreaterThanOrEqual(2, count($res->json('data'))); // in_app + email rows
    }

    public function test_deliveries_are_isolated_per_user(): void
    {
        $this->dispatcher->dispatch($this->payload());
        $other = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/notifications/deliveries')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertGreaterThan(0, NotificationDelivery::count());
    }
}
