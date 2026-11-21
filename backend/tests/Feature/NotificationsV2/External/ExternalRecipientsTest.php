<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Entitlements\PlanEntitlementGate;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/** Spec §9 recipients + subscriptions, through R's API (§16 «External recipients», isolation, entitlements). */
final class ExternalRecipientsTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private string $base = '/api/v1/settings/workspace/notifications/external';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        $this->tenant = $this->tenant('Alpha', 'agency');
        $this->owner = $this->member($this->tenant);
        $this->project = $this->project($this->tenant, 'Launch');
        DB::table('workspace_notification_settings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'org_domains' => json_encode(['alpha.test']), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function disable(string $key): void
    {
        DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => $key, 'enabled' => false, 'grace_days' => 0, 'keep_existing' => false, 'created_at' => now(), 'updated_at' => now()]);
        $gate = app(EntitlementGate::class);
        assert($gate instanceof PlanEntitlementGate);
        $gate->flush();
    }

    /** @param array<string, mixed> $over */
    private function payload(array $over = [], array $sub = []): array
    {
        return array_merge([
            'email' => 'finance@client.test', 'name' => 'Sara', 'salutation' => 'أستاذة سارة', 'role_key' => 'finance', 'language' => 'ar',
            'subscriptions' => [array_merge(['project_ids' => [$this->project->id], 'digest_types' => ['performance', 'spend'], 'period_key' => 'this_month_to_yesterday', 'frequency' => 'daily'], $sub)],
        ], $over);
    }

    public function test_options_come_from_the_workspace_type_profile(): void
    {
        $res = $this->actingAs($this->owner)->getJson("{$this->base}/options")->assertOk()
            ->assertJsonPath('data.workspace_type', 'agency')
            ->assertJsonPath('data.roles.3.key', 'client')
            ->assertJsonPath('data.roles.3.is_client_role', true);
        $this->assertSame('off', $res->json('data.entitlements')['branding.remove_platform_footprint']);

        $brand = $this->tenant('Brand', 'brand');
        $owner = $this->member($brand);
        $this->actingAs($owner)->getJson("{$this->base}/options")->assertOk()->assertJsonPath('data.workspace_type', 'business')->assertJsonPath('data.roles.0.key', 'owner');
    }

    public function test_an_outside_address_is_pending_until_it_confirms_and_archived_projects_are_excluded(): void
    {
        $archived = $this->project($this->tenant, 'Old', 'archived');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $r = $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload([], ['project_ids' => [$this->project->id, $archived->id]]))->assertCreated();

        $r->assertJsonPath('data.recipient.status', 'pending')
            ->assertJsonPath('data.recipient.salutation', 'أستاذة سارة')
            ->assertJsonPath('data.recipient.subscriptions.0.status', 'pending')
            ->assertJsonPath('data.recipient.subscriptions.0.project_ids', [$this->project->id]);
        $this->assertContains('archived_projects_excluded', $r->json('data.notes'));
        $this->assertContains('confirmation_email_required', $r->json('data.hints'));
        $this->assertSame(1, DB::table('usage_counters')->where('tenant_id', $this->tenant->id)->where('metric', 'notif.external_recipients')->value('count'));

        // Internal approval is refused for an outside address.
        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'x@other.test', 'approve_internally' => true]))->assertStatus(422);
    }

    public function test_an_org_domain_address_may_be_approved_internally_and_the_client_role_never(): void
    {
        $r = $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'cfo@alpha.test', 'approve_internally' => true]))->assertCreated();
        $r->assertJsonPath('data.recipient.status', 'active')->assertJsonPath('data.recipient.consent', 'approved_internally')
            ->assertJsonPath('data.recipient.approved_by', $this->owner->id)->assertJsonPath('data.recipient.subscriptions.0.status', 'active');
        $this->assertSame(1, DB::table('notification_subscription_log')->where('action', 'approved_internally')->where('actor_user_id', $this->owner->id)->count());

        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'client@alpha.test', 'role_key' => 'client', 'approve_internally' => true]))->assertStatus(422);
        $c = $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'client@alpha.test', 'role_key' => 'client'], ['review_mode' => false]))->assertCreated();
        $c->assertJsonPath('data.recipient.subscriptions.0.review_mode', true);
        $this->assertContains('client_role_review_stays_on', $c->json('data.notes'));
    }

    public function test_illogical_period_frequency_is_refused_with_the_alternative_and_types_are_mandatory(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload([], ['period_key' => 'yesterday', 'frequency' => 'monthly']))
            ->assertStatus(422)->assertJsonPath('errors.alternative.0', 'last_month');
        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload([], ['digest_types' => [], 'event_keys' => []]))->assertStatus(422);
        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload([], ['digest_types' => [], 'event_keys' => ['budget.overrun']]))->assertStatus(422); // internal-only event is not offered
        $this->actingAs($this->owner)->postJson("{$this->base}/period-check", ['period_key' => 'last_week', 'frequency' => 'daily'])->assertOk()->assertJsonPath('data.alternative', 'last_7');
    }

    public function test_cc_bcc_rules_entitlement_and_copy_consent(): void
    {
        $r = $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'boss@client.test', 'role_key' => 'client'], [
            'cc' => ['team@alpha.test', 'agency-partner@else.test', 'team@alpha.test'], 'bcc' => ['team@alpha.test', 'audit@alpha.test'],
        ]))->assertCreated();

        // Duplicate delivered once; an outside CC next to a client moves to BCC with a note.
        $r->assertJsonPath('data.recipient.subscriptions.0.cc', ['team@alpha.test'])
            ->assertJsonPath('data.recipient.subscriptions.0.bcc', ['audit@alpha.test', 'agency-partner@else.test']);
        $this->assertContains('cc_moved_to_bcc:agency-partner@else.test', $r->json('data.notes'));
        // The outside copy address waits for its own confirmation.
        $copy = DB::table('notification_external_recipients')->where('email', 'agency-partner@else.test')->first();
        $this->assertSame('pending', $copy->status);
        $this->assertSame('copy', DB::table('notification_subscriptions')->where('recipient_id', $copy->id)->value('template_key'));

        $this->disable('notifications.external.cc_bcc');
        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'n@client.test'], ['cc' => ['a@alpha.test']]))
            ->assertStatus(422)->assertJsonFragment(['entitlement:notifications.external.cc_bcc']);
        // Empty copy fields are a normal subscription.
        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'n@client.test']))->assertCreated();
    }

    public function test_custom_subject_needs_its_key_and_disabling_external_refuses_writes_but_keeps_data(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload([], ['subject_template' => 'أفضل أسبوع']))->assertCreated();
        $this->disable('notifications.external.custom_subject');
        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'b@client.test'], ['subject_template' => 'x']))->assertStatus(422);

        $this->disable('notifications.external');
        $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'c@client.test']))->assertForbidden()->assertJsonPath('meta.upgrade', true);
        $this->actingAs($this->owner)->getJson("{$this->base}/recipients")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_pause_resume_cancel_duplicate_and_the_log(): void
    {
        $id = $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload(['email' => 'cfo@alpha.test', 'approve_internally' => true]))->json('data.recipient.subscriptions.0.id');

        $this->actingAs($this->owner)->postJson("{$this->base}/subscriptions/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->actingAs($this->owner)->postJson("{$this->base}/subscriptions/{$id}/resume")->assertOk()->assertJsonPath('data.status', 'active');
        $dup = $this->actingAs($this->owner)->postJson("{$this->base}/subscriptions/{$id}/duplicate")->assertCreated()->json('data');
        $this->assertSame('active', $dup['status']);
        $this->actingAs($this->owner)->postJson("{$this->base}/subscriptions/{$id}/cancel")->assertStatus(422); // confirm required
        $this->actingAs($this->owner)->postJson("{$this->base}/subscriptions/{$id}/cancel", ['confirm' => true])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(2, DB::table('notification_subscriptions')->count()); // nothing deleted
        $this->assertEqualsCanonicalizing(['created', 'approved_internally', 'paused', 'resumed', 'cancelled'], DB::table('notification_subscription_log')->distinct()->pluck('action')->all());
    }

    public function test_another_workspace_sees_and_touches_nothing(): void
    {
        $id = $this->actingAs($this->owner)->postJson("{$this->base}/recipients", $this->payload())->json('data.recipient.id');
        $sub = DB::table('notification_subscriptions')->where('recipient_id', $id)->value('id');

        $other = $this->tenant('Beta', 'agency');
        $stranger = $this->member($other);
        $this->actingAs($stranger)->getJson("{$this->base}/recipients")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger)->getJson("{$this->base}/recipients/{$id}")->assertNotFound();
        $this->actingAs($stranger)->postJson("{$this->base}/subscriptions/{$sub}/pause")->assertNotFound();
        // A project of another workspace cannot be subscribed.
        $this->actingAs($stranger)->postJson("{$this->base}/recipients", $this->payload(['email' => 'z@x.test']))->assertStatus(422);
    }
}
