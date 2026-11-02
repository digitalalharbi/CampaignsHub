<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Services\ClientTeamService;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * SEC-CLUSTER-4 — the per-client project allow-list of a roster member binds, notification arrangements are the
 * actor's own to see and remove, a subscription's invoices need subscriptions.view, and removing a person from
 * one team never deletes an account another team still holds.
 */
final class RosterAndRecipientsCeilingTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
    }

    private function roster(): ClientTeamService
    {
        app(TenantContext::class)->setTenantId((string) $this->agency->id);

        return app(ClientTeamService::class);
    }

    private function metric(string $projectId, float $value): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'project_id' => $projectId,
            'external_account_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'acct:'.$projectId), 'external_campaign_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'camp:'.$projectId),
            'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => '2026-07-20', 'value' => $value, 'project_currency' => 'SAR',
            'attribution_window' => 'default', 'source_type' => 'api', 'data_freshness_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    public function test_a_roster_members_project_allow_list_limits_the_clients_analytics(): void
    {
        $second = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $this->clientA->id, 'name' => 'Second A', 'status' => 'active']);
        $this->metric((string) $this->projectA->id, 100);
        $this->metric((string) $second->id, 200);

        // On the roster only (no view_all, no named client scope), limited to the first project.
        $member = $this->operatorIn($this->agency, ['clients.view', 'clients.view_analytics']);
        $this->roster()->grant($this->clientA, $member->id, 'analyst', [(string) $this->projectA->id], $this->owner());

        $res = $this->actingAs($member, 'sanctum')->getJson("/api/v1/app/clients/{$this->clientA->id}/analytics?from=2026-07-01&to=2026-07-31")->assertOk();
        $this->assertSame([(string) $this->projectA->id], array_column($res->json('data.projects'), 'project_id'));

        // Unrestricted on the roster, and the agency owner, still see the whole client.
        $all = $this->operatorIn($this->agency, ['clients.view', 'clients.view_analytics']);
        $this->roster()->grant($this->clientA, $all->id, 'analyst', null, $this->owner());
        $this->assertCount(2, $this->actingAs($all, 'sanctum')->getJson("/api/v1/app/clients/{$this->clientA->id}/analytics?from=2026-07-01&to=2026-07-31")->json('data.projects'));
        $this->assertCount(2, $this->actingAs($this->owner(), 'sanctum')->getJson("/api/v1/app/clients/{$this->clientA->id}/analytics?from=2026-07-01&to=2026-07-31")->json('data.projects'));
    }

    public function test_notification_arrangements_are_listed_and_removed_only_within_the_actors_reach(): void
    {
        $colleague = $this->operatorIn($this->agency, ['tasks.view'], null, 'colleague@ceiling.test');
        $row = fn (string $projectId): string => tap((string) Str::uuid(), fn ($id) => DB::table('notification_recipients')->insert([
            'id' => $id, 'tenant_id' => $this->agency->id, 'project_id' => $projectId, 'user_id' => $colleague->id, 'category' => 'reports',
            'created_by' => $colleague->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $onA = $row((string) $this->projectA->id);
        $onB = $row((string) $this->projectB->id);
        $op = $this->scopedTo($this->clientB, ['settings.manage']);

        $listed = array_column($this->actingAs($op, 'sanctum')->getJson('/api/v1/settings/notifications/recipients')->assertOk()->json('data.recipients'), 'id');
        $this->assertSame([$onB], $listed);

        $this->actingAs($op, 'sanctum')->deleteJson("/api/v1/settings/notifications/recipients/{$onA}")->assertOk();
        $this->assertTrue(DB::table('notification_recipients')->where('id', $onA)->exists(), 'a sibling client\'s arrangement is not the actor\'s to delete');

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/settings/notifications/recipients', ['user_id' => $colleague->id, 'category' => 'reports'])->assertForbidden();

        $this->actingAs($this->owner(), 'sanctum')->deleteJson("/api/v1/settings/notifications/recipients/{$onA}")->assertOk();
        $this->assertFalse(DB::table('notification_recipients')->where('id', $onA)->exists());
    }

    public function test_subscription_invoices_need_subscriptions_view(): void
    {
        $plain = $this->operatorIn($this->agency, ['clients.view']);
        $reader = $this->operatorIn($this->agency, ['subscriptions.view']);

        $this->actingAs($plain, 'sanctum')->getJson('/api/v1/subscriptions/invoices')->assertForbidden();
        $this->actingAs($reader, 'sanctum')->getJson('/api/v1/subscriptions/invoices')->assertOk();
    }

    public function test_removing_a_member_keeps_the_account_when_another_team_holds_it_suspended(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $shared = $this->operatorIn($this->agency, ['tasks.view'], null, 'shared@ceiling.test');
        $this->grantMembership($shared, $other, Portal::Agency);
        DB::table('memberships')->where('user_id', $shared->id)->where('tenant_id', $other->id)->update(['status' => 'suspended']);

        $this->actingAs($this->owner(), 'sanctum')->deleteJson("/api/v1/settings/team/{$shared->uuid}")->assertOk();

        $this->assertTrue(User::query()->whereKey($shared->id)->exists(), 'the other workspace still holds this person');
        $this->assertSame(0, DB::table('memberships')->where('user_id', $shared->id)->where('tenant_id', $this->agency->id)->count());
    }
}
