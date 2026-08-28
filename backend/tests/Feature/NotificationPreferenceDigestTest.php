<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MAIL-004 — a person controls their own delivery, and only their own.
 *
 * The preference row is the one part of this feature a user writes directly, which makes it the one
 * part an attacker can reach. These cover both halves: that the settings actually persist, and that
 * the dangerous fields are validated rather than trusted.
 */
final class NotificationPreferenceDigestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-prefs', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Member', 'slug' => 'member-prefs']);

        $this->user = User::create(['name' => 'P', 'email' => 'p@prefs.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'app', 'status' => 'active']);
        $this->user->assignRole($role);
        $this->user = $this->user->fresh();
    }

    /** @return array<string,mixed> */
    private function payload(array $over = []): array
    {
        return array_merge([
            'channels' => ['in_app' => true, 'email' => true],
            // A real map: `required|array` rejects `[]`, and the client always sends the full set.
            'categories' => ['reports' => ['in_app' => true, 'email' => true]],
            'frequency' => 'daily',
            'digests' => ['daily' => true, 'weekly' => false],
            'timezone' => 'Europe/London',
            'locale' => 'en',
            'digest_hour' => 7,
        ], $over);
    }

    /**
     * Off by default, and it stays off until somebody says otherwise.
     *
     * An opt-in that defaults to on is not an opt-in. The first scheduled run after a deploy is the
     * worst possible moment to discover that a whole installation was subscribed.
     */
    public function test_the_digests_are_off_until_the_user_turns_them_on(): void
    {
        $body = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/settings/notifications')
            ->assertOk()
            ->json('data');

        $this->assertFalse($body['digests']['daily']);
        $this->assertFalse($body['digests']['weekly']);
        $this->assertSame(8, $body['digest_hour']);
    }

    /** What a person chooses is what comes back, and what the scheduler will read. */
    public function test_the_chosen_digest_hour_timezone_and_language_are_persisted(): void
    {
        $body = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/settings/notifications', $this->payload())
            ->assertOk()
            ->json('data');

        $this->assertTrue($body['digests']['daily']);
        $this->assertSame('Europe/London', $body['timezone']);
        $this->assertSame('en', $body['locale']);
        $this->assertSame(7, $body['digest_hour']);

        $row = DB::table('notification_preferences')->where('user_id', $this->user->id)->first();
        $this->assertSame('Europe/London', $row->timezone);
        $this->assertSame(7, (int) $row->digest_hour);
    }

    /**
     * A timezone this process cannot resolve is refused at the boundary.
     *
     * Stored, it would throw inside the hourly sweep and abort it — one malformed row silencing
     * every other recipient's digest. Validated against PHP's own list rather than a regex, because
     * the list is the only authority on what `setTimezone` will accept.
     */
    public function test_an_unresolvable_timezone_is_refused_rather_than_stored(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/settings/notifications', $this->payload(['timezone' => 'Mars/Olympus']))
            ->assertStatus(422);

        $this->assertNull(DB::table('notification_preferences')->where('user_id', $this->user->id)->first());
    }

    /** An hour outside a day is refused — 25:00 would silently never match. */
    public function test_an_hour_outside_the_day_is_refused(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/settings/notifications', $this->payload(['digest_hour' => 25]))
            ->assertStatus(422);
    }

    /** Nobody edits anybody else's delivery: the row is keyed on the authenticated user. */
    public function test_the_preference_is_written_for_the_authenticated_user_only(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@prefs.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $other->id, 'portal' => 'app', 'status' => 'active']);

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/settings/notifications', $this->payload(['user_id' => $other->id]))
            ->assertOk();

        $this->assertSame(1, DB::table('notification_preferences')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, DB::table('notification_preferences')->where('user_id', $other->id)->count());
    }

    /**
     * EMAIL-SETTINGS-DEPTH-001 — the recommendations toggle round-trips, and defaults off.
     *
     * It rides in the `digests` map beside the daily/weekly opt-ins rather than in a column of its
     * own, so a preferences row written before the setting existed simply has no key — and `show`
     * must answer `false` for it rather than omitting it, or a client cannot render the switch at all.
     */
    public function test_the_recommendations_toggle_defaults_off_and_round_trips(): void
    {
        $body = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/settings/notifications')->assertOk()->json('data');

        $this->assertFalse($body['digests']['recommendations'], 'An untouched account was opted in.');

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/settings/notifications', $this->payload([
                'digests' => ['daily' => true, 'weekly' => false, 'recommendations' => true],
            ]))->assertOk();

        $body = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/settings/notifications')->assertOk()->json('data');

        $this->assertTrue($body['digests']['recommendations']);

        // And it can be turned back off — a switch that only latches on is not a switch.
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/settings/notifications', $this->payload([
                'digests' => ['daily' => true, 'weekly' => false, 'recommendations' => false],
            ]))->assertOk();

        $body = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/settings/notifications')->assertOk()->json('data');

        $this->assertFalse($body['digests']['recommendations']);
    }
}
