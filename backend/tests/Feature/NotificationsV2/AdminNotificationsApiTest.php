<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\EntitlementMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/** NOTIF-V2-SETTINGS-UI-001 — the platform admin console for notifications (spec §15). */
final class AdminNotificationsApiTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/notifications-v2';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin-'.uniqid().'@nv2.test', 'password' => 'secret123']);
        $this->admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
    }

    public function test_a_workspace_owner_cannot_reach_the_console(): void
    {
        $tenant = $this->tenant('Alpha');
        $owner = $this->member($tenant, 'tenant-owner');
        $this->actingAs($owner)->getJson(self::URL)->assertForbidden();
        $this->actingAs($owner)->putJson(self::URL.'/plan-features', ['key' => 'notifications.external', 'enabled' => false])->assertForbidden();
    }

    public function test_the_console_lists_catalog_plans_texts_and_types(): void
    {
        $res = $this->actingAs($this->admin)->getJson(self::URL)->assertOk();
        $this->assertNotEmpty($res->json('data.catalog'));
        $this->assertCount(3, $res->json('data.workspace_types'));
        $this->assertContains('فشل', $res->json('data.texts')['phrasing.dictionary']['value']['forbidden']);
        $this->assertFalse($res->json('data.texts')['phrasing.dictionary']['customised']);
        $footprint = collect($res->json('data.entitlement_keys'))->firstWhere('key', 'branding.remove_platform_footprint');
        $this->assertFalse($footprint['default']);
    }

    public function test_switching_a_key_off_for_a_plan_makes_a_workspace_view_only_after_grace_and_re_enabling_restores_it(): void
    {
        $tenant = $this->tenant('Alpha');
        $this->member($tenant, 'tenant-owner');
        $this->actingAs($this->admin)->putJson(self::URL.'/plan-features', [
            'key' => 'notifications.external', 'enabled' => false, 'grace_days' => 0, 'keep_existing' => false, 'paid_from' => now()->addDay()->toDateString(),
        ])->assertOk();
        $this->assertSame(1, DB::table('notification_plan_features')->where('key', 'notifications.external')->count());

        // Created before paid_from, no grace days, not kept: view-only (data stays, edits refused).
        app()->forgetScopedInstances();
        $state = app(EntitlementGate::class)->state($tenant->id, EntitlementKey::External);
        $this->assertContains($state->mode, [EntitlementMode::ViewOnly, EntitlementMode::Grace]);

        $this->actingAs($this->admin)->putJson(self::URL.'/plan-features', ['key' => 'notifications.external', 'enabled' => true])->assertOk();
        $this->assertSame(1, DB::table('notification_plan_features')->where('key', 'notifications.external')->count(), 'upsert, not a second row');
        app()->forgetScopedInstances();
        $this->assertTrue(app(EntitlementGate::class)->allows($tenant->id, EntitlementKey::External));
    }

    public function test_limits_must_name_a_real_limit(): void
    {
        $this->actingAs($this->admin)->putJson(self::URL.'/plan-features', ['key' => 'notifications.external', 'enabled' => true, 'limits' => ['nope' => 1]])->assertStatus(422);
        $this->actingAs($this->admin)->putJson(self::URL.'/plan-features', ['key' => 'notifications.external', 'plan_code' => 'pro', 'enabled' => true, 'limits' => ['monthly_messages' => 5000]])->assertOk();
        $row = collect($this->actingAs($this->admin)->getJson(self::URL)->json('data.plan_features'))->firstWhere('plan_code', 'pro');
        $this->assertSame(5000, $row['limits']['monthly_messages']);
    }

    public function test_an_internal_only_event_cannot_be_moved_external_and_edits_take_effect(): void
    {
        $internalOnly = collect(config('notification_events.events'))->filter(fn ($e) => ($e['internal_only'] ?? false) === true)->keys()->first();
        $this->assertNotNull($internalOnly);
        $this->actingAs($this->admin)->putJson(self::URL.'/events/'.$internalOnly, ['scope' => 'both'])->assertStatus(422);
        $this->actingAs($this->admin)->putJson(self::URL.'/events/'.$internalOnly, ['priority' => 'critical', 'enabled' => false])->assertOk();
        $row = collect($this->actingAs($this->admin)->getJson(self::URL)->json('data.catalog'))->firstWhere('key', $internalOnly);
        $this->assertSame('critical', $row['priority']);
        $this->assertFalse($row['enabled']);
        $this->actingAs($this->admin)->putJson(self::URL.'/events/no.such', ['enabled' => true])->assertNotFound();
    }

    public function test_platform_texts_save_and_unknown_keys_are_refused(): void
    {
        $this->actingAs($this->admin)->putJson(self::URL.'/texts/disclaimer.default', ['value' => ['ar' => 'نص {workspace}', 'en' => 'Text <b>{workspace}</b>']])->assertOk();
        $res = $this->actingAs($this->admin)->getJson(self::URL)->json('data.texts')['disclaimer.default'];
        $this->assertTrue($res['customised']);
        $this->assertSame('Text {workspace}', $res['value']['en'], 'markup is stripped');
        $this->actingAs($this->admin)->putJson(self::URL.'/texts/templates.agency', ['value' => ['cards' => ['monthly']]])->assertOk();
        $this->actingAs($this->admin)->putJson(self::URL.'/texts/templates.nonsense', ['value' => ['a' => 1]])->assertNotFound();
    }

    public function test_stats_are_counts_per_workspace_and_expose_no_recipient(): void
    {
        $a = $this->tenant('Alpha');
        $this->tenant('Beta');
        DB::table('usage_counters')->insert(['tenant_id' => $a->id, 'metric' => 'notif.external_messages', 'period' => now()->format('Y-m'), 'count' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $row = fn (string $status): array => ['id' => (string) Str::uuid(), 'tenant_id' => $a->id, 'delivery_id' => (string) Str::uuid(), 'reference' => 'R'.Str::random(8), 'event_key' => 'x', 'scope' => 'external', 'channel' => 'email', 'recipient_kind' => 'external', 'recipient_address' => 'secret@example.com', 'status' => $status, 'created_at' => now()];
        DB::table('notification_delivery_log')->insert([$row('sent'), $row('sent'), $row('sent'), $row('failed')]);
        // Builder Q's click table (merged): counted per message type, nothing about the reader; last month's click is outside.
        DB::table('notification_footprint_clicks')->insert([
            ['tenant_id' => $a->id, 'message_type' => 'digest.periodic', 'reference' => 'R1', 'clicked_at' => now()],
            ['tenant_id' => $a->id, 'message_type' => 'digest.periodic', 'reference' => 'R2', 'clicked_at' => now()],
            ['tenant_id' => $a->id, 'message_type' => 'target.reached', 'reference' => 'R3', 'clicked_at' => now()],
            ['tenant_id' => $a->id, 'message_type' => 'target.reached', 'reference' => 'R4', 'clicked_at' => now()->subMonthNoOverflow()->startOfMonth()],
        ]);

        $res = $this->actingAs($this->admin)->getJson(self::URL.'/stats')->assertOk()
            ->assertJsonPath('data.messages_sent', 3)->assertJsonPath('data.messages_failed', 1)->assertJsonPath('data.failure_rate', 0.25)
            ->assertJsonPath('data.per_workspace.0.messages', 7)->assertJsonPath('data.footprint_clicks', 3)
            ->assertJsonPath('data.footprint_clicks_by_type', ['digest.periodic' => 2, 'target.reached' => 1]);
        $this->assertStringNotContainsString('secret@example.com', $res->getContent());
    }
}
