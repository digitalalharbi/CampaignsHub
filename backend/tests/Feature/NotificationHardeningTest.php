<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Services\NotificationDispatcher;
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
        // Scope dies with the request since ADR 0002; this test creates rows directly
        // between requests, so it holds its tenant for the whole test.
        $this->holdingTenant((string) $this->tenant->id);
        $this->user = User::create(['name' => 'U', 'email' => 'u@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
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

    /**
     * The window is the RECIPIENT's, not the server's — MAIL-013.
     *
     * The fixture builds it in their own timezone for that reason. It used to build it from the
     * process clock, which passed while the product was wrong: «22:00 to 08:00» meant whatever hour
     * the container thought it was, so a reader in Riyadh on a UTC host had their night held three
     * hours late in both directions.
     */
    public function test_quiet_hours_suppress_email_but_keep_in_app(): void
    {
        $this->quietWindowAround('Asia/Riyadh');

        $n = $this->dispatcher->dispatch($this->payload(['entity_id' => 'REQ-QH']));
        $this->assertNotNull($n); // in-app inbox still receives it
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $n->id, 'channel' => 'email', 'status' => 'suppressed_by_quiet_hours']);
    }

    /**
     * The same window, stored against a timezone where it is the middle of the working day.
     *
     * This is the assertion the old test could not make. `Pacific/Kiritimati` is UTC+14 and
     * `Etc/GMT+12` is UTC-12, so a window built around one person's «now» cannot also contain the
     * other's — whatever the server's clock happens to say.
     */
    public function test_a_quiet_window_belongs_to_the_hour_the_reader_is_living_in(): void
    {
        $this->quietWindowAround('Pacific/Kiritimati', 'Etc/GMT+12');

        $n = $this->dispatcher->dispatch($this->payload(['entity_id' => 'REQ-TZ']));

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $n->id, 'channel' => 'email', 'status' => 'awaiting_credentials',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', ['status' => 'suppressed_by_quiet_hours']);
    }

    /**
     * A quiet window of ±30 minutes around «now» in `$windowTimezone`, stored against `$storedAs`.
     *
     * When the two differ, the window is deliberately one the reader is NOT inside.
     */
    private function quietWindowAround(string $windowTimezone, ?string $storedAs = null): void
    {
        $local = now()->copy()->setTimezone($windowTimezone);

        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]),
            'categories' => json_encode([]),
            'quiet_hours' => json_encode([
                'enabled' => true,
                'start' => $local->copy()->subMinutes(30)->format('H:i'),
                'end' => $local->copy()->addMinutes(30)->format('H:i'),
            ]),
            'timezone' => $storedAs ?? $windowTimezone,
            'frequency' => 'realtime', 'created_at' => now(), 'updated_at' => now(),
        ]);
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
        $other = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($other, $this->tenant);
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/notifications/deliveries')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertGreaterThan(0, NotificationDelivery::count());
    }
}
