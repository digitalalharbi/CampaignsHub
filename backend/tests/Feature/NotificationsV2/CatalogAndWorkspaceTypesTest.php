<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/** NOTIF-V2-ENGINE-001 — the one event catalog and the workspace-type abstraction (spec §0, §3). */
final class CatalogAndWorkspaceTypesTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_the_catalog_seeds_the_spec_events_with_internal_only_ones_never_external(): void
    {
        $registry = app(EventRegistry::class);

        foreach (['budget.threshold_75', 'budget.threshold_90', 'budget.threshold_100', 'budget.overrun', 'spend_limit.reached', 'sync.failed',
            'connection.expired', 'automation.executed', 'recommendation.awaiting_approval', 'campaign.spend_without_results',
            'external.recipient_unsubscribed', 'project.no_budget_for_period', 'plan.grace_ending', 'followup.morning_brief'] as $key) {
            $this->assertSame(Scope::Internal, $registry->get($key)?->scope, $key);
        }
        foreach (['digest.periodic', 'target.reached', 'continuity.planning', 'digest.month_end', 'digest.month_start'] as $key) {
            $event = $registry->get($key);
            $this->assertSame(Scope::External, $event?->scope, $key);
            $this->assertSame([Channel::Email], $event->channels, $key);
        }
        // §7: every sensitive operational event is internal only.
        foreach (['sync.failed', 'connection.expiring', 'spend_limit.reached', 'budget.overrun', 'automation.executed', 'campaign.spend_without_results'] as $key) {
            $this->assertTrue($registry->get($key)?->internalOnly, $key);
        }
        foreach ($registry->forScope(Scope::External) as $event) {
            $this->assertFalse($event->internalOnly, $event->key);
        }
    }

    public function test_every_event_has_a_title_and_body_in_both_languages_for_each_of_its_scopes(): void
    {
        foreach (app(EventRegistry::class)->all() as $event) {
            foreach ([Scope::Internal, Scope::External] as $scope) {
                if (! $event->scope->includes($scope)) {
                    continue;
                }
                foreach (['ar', 'en'] as $locale) {
                    foreach (['title', 'body'] as $part) {
                        $key = $event->textKey($scope, $part);
                        $this->assertNotSame($key, __($key, [], $locale), "{$key} [{$locale}]");
                    }
                }
            }
        }
    }

    public function test_an_admin_override_is_merged_but_cannot_make_an_internal_only_event_external(): void
    {
        DB::table('notification_event_catalog')->insert([
            ['id' => (string) Str::uuid(), 'event_key' => 'sync.failed', 'overrides' => json_encode(['scope' => 'external', 'priority' => 'critical']), 'enabled' => true, 'version' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'event_key' => 'record.reached', 'overrides' => json_encode([]), 'enabled' => false, 'version' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $registry = app(EventRegistry::class);

        $sync = $registry->get('sync.failed');
        $this->assertSame(Scope::Internal, $sync?->scope);
        $this->assertSame('critical', $sync->priority->value);
        $this->assertSame(2, $sync->version);
        $this->assertFalse($registry->get('record.reached')?->enabled);
        $this->assertNotContains('record.reached', array_map(static fn ($e) => $e->key, $registry->forScope(Scope::External)));
    }

    public function test_each_workspace_type_has_its_own_roles_and_terms_and_a_client_portal_login_sees_its_own_profile(): void
    {
        $this->seedPermissions();
        $types = app(WorkspaceTypes::class);
        $agency = $this->tenant('Agency', 'agency');
        $brand = $this->tenant('Brand', 'brand');
        $portalUser = $this->member($agency, 'client-viewer', [], 'portal');

        $this->assertSame(['finance', 'project_manager', 'admin', 'client', 'other'], $types->profileFor($agency->id)->roles);
        $this->assertSame(['owner', 'marketing', 'finance', 'other'], $types->profileFor($brand->id)->roles);
        $this->assertSame('client_portal', $types->profileFor($agency->id, (int) $portalUser->id)->key);
        $this->assertSame(['account_owner', 'team'], $types->profileFor($agency->id, (int) $portalUser->id)->roles);
        $this->assertFalse($types->profileFor($agency->id, (int) $portalUser->id)->seesSensitiveEvents);

        $this->assertSame('عميل', __($types->profileFor($agency->id)->terminology['client'], [], 'ar'));
        $this->assertSame('علامة', __($types->profileFor($brand->id)->terminology['client'], [], 'ar'));
        $this->assertSame('حملة', __($types->profileFor($brand->id)->terminology['project'], [], 'ar'));
    }

    public function test_a_new_workspace_of_any_type_works_on_defaults(): void
    {
        $tenant = $this->tenant('Fresh', 'self_serve_company');
        $settings = app(WorkspaceSettingsRepository::class)->for($tenant->id);

        $this->assertSame('business', $settings->typeKey);
        $this->assertSame('Asia/Riyadh', $settings->timezone);
        $this->assertSame([0, 1, 2, 3, 4], $settings->workDays);
        $this->assertTrue($settings->quietHours['enabled']);
        $this->assertSame([], $settings->orgDomains);
    }
}
