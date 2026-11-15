<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/** NOTIF-V2-SETTINGS-UI-001 — the data behind the one workspace notification settings page, for every workspace type. */
final class WorkspaceSettingsApiTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private const URL = '/api/v1/settings/workspace/notifications/settings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_each_workspace_type_gets_its_own_roles_and_terms_from_one_endpoint(): void
    {
        $expect = ['agency' => ['admin', 'عميل', 'مشروع'], 'brand' => ['owner', 'علامة', 'حملة']];
        foreach (['agency' => null, 'brand' => 'brand'] as $name => $accountType) {
            $tenant = $this->tenant(ucfirst($name), $accountType);
            $owner = $this->member($tenant, 'tenant-owner');
            $res = $this->actingAs($owner)->getJson(self::URL)->assertOk();
            $type = $res->json('data.workspace.type');
            $this->assertSame($expect[$name][0], $type['responsible_role']);
            $this->assertSame($expect[$name][1], $type['term_labels']['client']['ar']);
            $this->assertSame($expect[$name][2], $type['term_labels']['project']['ar']);
            $this->assertTrue($res->json('data.first_run'), 'a new workspace of any type starts on defaults with the first-run wizard');
            $this->assertNotEmpty($res->json('data.events'));
        }
    }

    public function test_settings_round_trip_and_are_isolated_per_workspace(): void
    {
        $a = $this->tenant('Alpha');
        $ownerA = $this->member($a, 'tenant-owner');
        $b = $this->tenant('Beta');
        $ownerB = $this->member($b, 'tenant-owner');

        $this->actingAs($ownerA)->putJson(self::URL, [
            'org_domains' => ['Alpha.example'],
            'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00'],
            'routing' => ['budget.threshold_75' => ['roles' => ['finance'], 'channels' => ['in_app'], 'enabled' => true]],
            'settings' => ['sender' => ['name' => 'Alpha Team'], 'contact_request' => true, 'first_run_completed' => true, 'not_owned_by_page' => 'x'],
        ])->assertOk()
            ->assertJsonPath('data.settings.org_domains.0', 'alpha.example')
            ->assertJsonPath('data.settings.quiet_hours.start', '22:00')
            ->assertJsonPath('data.settings.settings.sender.name', 'Alpha Team')
            ->assertJsonPath('data.first_run', false);

        $stored = DB::table('workspace_notification_settings')->where('tenant_id', $a->id)->value('settings');
        $this->assertStringNotContainsString('not_owned_by_page', (string) $stored);

        $this->actingAs($ownerB)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.settings.org_domains', [])
            ->assertJsonPath('data.first_run', true);
    }

    public function test_unknown_event_and_role_are_refused(): void
    {
        $tenant = $this->tenant('Alpha');
        $owner = $this->member($tenant, 'tenant-owner');
        $this->actingAs($owner)->putJson(self::URL, ['routing' => ['no.such.event' => ['enabled' => false]]])->assertStatus(422);
        $this->actingAs($owner)->putJson(self::URL, ['routing' => ['budget.threshold_75' => ['roles' => ['client_portal_only']]]])->assertStatus(422);
    }

    public function test_view_only_external_key_refuses_its_part_but_keeps_the_internal_part_editable(): void
    {
        $tenant = $this->tenant('Alpha');
        $owner = $this->member($tenant, 'tenant-owner');
        DB::table('notification_plan_features')->insert([
            'id' => (string) Str::uuid(), 'key' => EntitlementKey::External->value, 'plan_code' => null, 'workspace_type' => null,
            'enabled' => false, 'grace_days' => 0, 'keep_existing' => false, 'paid_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_entitlement_states')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'key' => EntitlementKey::External->value, 'mode' => 'view_only', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($owner)->putJson(self::URL, ['settings' => ['sender' => ['name' => 'X']]])
            ->assertForbidden()->assertJsonPath('meta.upgrade', true);

        $this->actingAs($owner)->putJson(self::URL, [
            'quiet_hours' => ['enabled' => false, 'start' => '21:00', 'end' => '08:00'],
            'settings' => ['sender' => ['name' => 'X']],
        ])->assertOk()->assertJsonPath('data.refused.0', EntitlementKey::External->value)
            ->assertJsonPath('data.settings.quiet_hours.enabled', false);
        $saved = json_decode((string) DB::table('workspace_notification_settings')->where('tenant_id', $tenant->id)->value('settings'), true);
        $this->assertArrayNotHasKey('sender', (array) $saved);
    }

    public function test_a_sub_feature_the_plan_no_longer_includes_locks_only_its_own_setting(): void
    {
        $tenant = $this->tenant('Alpha');
        $owner = $this->member($tenant, 'tenant-owner');
        DB::table('workspace_entitlement_states')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'key' => EntitlementKey::ExternalCcBcc->value, 'mode' => 'view_only', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($owner)->putJson(self::URL, ['settings' => ['cc_bcc' => ['default_cc' => ['a@x.test']], 'sender' => ['name' => 'Kept']]])
            ->assertOk()->assertJsonPath('data.refused.0', EntitlementKey::ExternalCcBcc->value)
            ->assertJsonPath('data.settings.settings.sender.name', 'Kept');
        $saved = json_decode((string) DB::table('workspace_notification_settings')->where('tenant_id', $tenant->id)->value('settings'), true);
        $this->assertArrayNotHasKey('cc_bcc', $saved);
    }

    public function test_a_member_without_settings_permission_is_refused(): void
    {
        $tenant = $this->tenant('Alpha');
        $viewer = $this->member($tenant, 'viewer', ['projects.view']);
        $this->actingAs($viewer)->getJson(self::URL)->assertForbidden();
        $this->actingAs($viewer)->putJson(self::URL, ['timezone' => 'UTC'])->assertForbidden();
    }

    public function test_kpis_count_only_this_workspace(): void
    {
        $a = $this->tenant('Alpha');
        $ownerA = $this->member($a, 'tenant-owner');
        $b = $this->tenant('Beta');
        $this->member($b, 'tenant-owner');
        $row = fn (Tenant $t, string $status, string $scope = 'external', string $channel = 'email'): array => [
            'id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'delivery_id' => (string) Str::uuid(), 'reference' => 'R'.Str::random(8),
            'event_key' => 'x', 'scope' => $scope, 'channel' => $channel, 'recipient_kind' => 'external', 'status' => $status, 'created_at' => now(),
        ];
        DB::table('notification_delivery_log')->insert([
            $row($a, 'sent'), $row($a, 'sent'), $row($a, 'held_for_review'), $row($a, 'held_for_privacy'), $row($a, 'failed'),
            $row($a, 'failed', 'internal', 'push'), $row($b, 'sent'), $row($b, 'held_for_review'),
        ]);

        $this->actingAs($ownerA)->getJson('/api/v1/settings/workspace/notifications/kpis')->assertOk()
            ->assertJsonPath('data.external.sent_this_week', 2)
            ->assertJsonPath('data.external.held_for_review', 1)
            ->assertJsonPath('data.external.held_for_privacy', 1)
            ->assertJsonPath('data.external.failed', 1)
            ->assertJsonPath('data.internal.push_failures', 1)
            ->assertJsonPath('data.external.awaiting_confirmation', 0);
    }
}
