<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * NOTIF-SOUND-001 — the server half of the ping: per-person on/off and volume, and «is it quiet for them right now».
 *
 * The page decides «a NEW critical or warning, once per batch»; the server decides «enabled» and «quiet», because only it knows
 * the person's timezone and stored quiet hours. Default quiet hours are 22:00–08:00 on the person's own clock.
 */
final class BellSoundSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create(['name' => 'Sound', 'slug' => 'sound', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->agency->id);
        $this->user = User::create(['name' => 'S', 'email' => 's@sound.dev', 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'projects.view.all');
        $this->user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $this->user, tenant: $this->agency, portal: Portal::Agency, role: 'member'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function sound(): array
    {
        return $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/notifications/summary')->assertOk()->json('data.sound');
    }

    public function test_the_defaults_are_sound_on_at_a_low_volume(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC')); // noon in Riyadh

        $this->assertSame(['enabled' => true, 'volume' => 35, 'quiet' => false], $this->sound());
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/notifications/alert-settings')->assertOk()
            ->assertJsonPath('data.sound_enabled', true)->assertJsonPath('data.sound_volume', 35)
            ->assertJsonPath('data.quiet_hours.is_default', true)->assertJsonPath('data.quiet_hours.start', '22:00')->assertJsonPath('data.quiet_hours.end', '08:00');
    }

    public function test_a_person_can_switch_the_sound_off_and_set_the_volume_and_it_is_theirs_alone(): void
    {
        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/notifications/alert-settings', ['sound_enabled' => false, 'sound_volume' => 60])
            ->assertOk()->assertJsonPath('data.sound_enabled', false)->assertJsonPath('data.sound_volume', 60);

        $this->assertFalse($this->sound()['enabled']);
        $this->assertSame(60, $this->sound()['volume']);

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/notifications/alert-settings', ['sound_volume' => 101])->assertStatus(422);
        // A partial save keeps what it did not mention.
        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/notifications/alert-settings', ['sound_enabled' => true])->assertOk();
        $this->assertSame(60, $this->sound()['volume']);

        $other = User::create(['name' => 'O', 'email' => 'o@sound.dev', 'password' => 'secret123', 'email_verified_at' => now()]);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $other, tenant: $this->agency, portal: Portal::Agency, role: 'member'));
        $this->assertSame(35, $this->actingAs($other, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.sound.volume'));
    }

    public function test_it_is_quiet_from_22_to_08_riyadh_by_default_and_not_in_the_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 19:30:00', 'UTC')); // 22:30 Riyadh
        $this->assertTrue($this->sound()['quiet']);

        Carbon::setTestNow(Carbon::parse('2026-11-15 04:30:00', 'UTC')); // 07:30 Riyadh
        $this->assertTrue($this->sound()['quiet']);

        Carbon::setTestNow(Carbon::parse('2026-11-15 05:30:00', 'UTC')); // 08:30 Riyadh
        $this->assertFalse($this->sound()['quiet']);
    }

    public function test_an_explicit_choice_beats_the_default_either_way(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 19:30:00', 'UTC')); // 22:30 Riyadh

        // «Quiet hours off» is an explicit choice: the night default does not apply.
        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/settings/notifications', ['quiet_hours' => ['enabled' => false, 'start' => '22:00', 'end' => '08:00']])->assertOk();
        $this->assertFalse($this->sound()['quiet']);

        // Their own window, on their own clock.
        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/settings/notifications', ['quiet_hours' => ['enabled' => true, 'start' => '12:00', 'end' => '14:00']])->assertOk();
        $this->assertFalse($this->sound()['quiet']);
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC')); // 13:00 Riyadh
        $this->assertTrue($this->sound()['quiet']);
    }
}
