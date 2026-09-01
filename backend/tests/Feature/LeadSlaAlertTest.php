<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Models\Lead;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEAD-SLA-NOTIFICATION-001 — the follow-up promises, on the engine that already exists.
 *
 * The requirement refuses a second notification engine, and this is what honouring that looks like:
 * three new rule TYPES, no new dispatcher, no new cooldown, no new dedup, no new audit trail. If any
 * of those had been rebuilt here they would drift, and the day they drifted an operator would have
 * two alert histories that disagree and no way to tell which is the real one.
 *
 * ## Why three types and not one «SLA breach»
 *
 * They are three different failures with three different people to tell: nobody owns it (a router's
 * problem), the owner has not tried (that owner's problem), and a promise made has passed (the
 * person who made it). A single alert would send every reader into the product to find out which,
 * which is the trip an alert exists to save.
 *
 * ## The SLA is the client's, not ours
 *
 * `alert_rules` already carries `project_id` and a `threshold` map, so «answer within the hour» and
 * «answer within the day» are two rules rather than a constant somebody had to compromise on. No
 * migration was needed and none was written.
 */
final class LeadSlaAlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'sla-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    /** @param array<string,mixed> $over */
    private function rule(string $type, array $over = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'type' => $type,
            'name' => $type,
            'cooldown_minutes' => 720,
            'channels' => ['in_app'],
            'severity' => 'warning',
            'active' => true,
        ], $over));
    }

    /** @param array<string,mixed> $over */
    private function lead(array $over = []): Lead
    {
        $lead = new Lead;
        $lead->forceFill(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Buyer', 'phone' => '+966500000001',
            'source' => 'provider', 'status' => LeadStage::New->value,
            'received_at' => Carbon::now()->subHours(4),
        ], $over))->save();

        return $lead;
    }

    private function events(): int
    {
        return AlertEvent::query()->where('tenant_id', $this->tenant->id)->count();
    }

    public function test_a_lead_nobody_owns_past_the_sla_raises_one_alert(): void
    {
        $this->lead();
        $this->lead();

        $raised = app(AlertEvaluator::class)->evaluateRule($this->rule('lead_unassigned', [
            'threshold' => ['minutes' => 60],
        ]));

        $this->assertSame(1, $raised, 'two unowned leads are one situation and one person to tell');

        $event = AlertEvent::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame(2, $event->context['count']);
        // The engine keeps the human sentence inside `context`, beside the figures it was built from.
        // «1 hour», not «60 minutes» and never «1 minutes» — an alert reads as a person wrote it.
        $this->assertStringContainsString('1 hour', (string) $event->context['message']);
    }

    /** Inside the SLA there is nothing to say. The clock is the client's, and it has not run out. */
    public function test_a_lead_still_inside_the_sla_raises_nothing(): void
    {
        $this->lead(['received_at' => Carbon::now()->subMinutes(10)]);

        app(AlertEvaluator::class)->evaluateRule($this->rule('lead_unassigned', ['threshold' => ['minutes' => 60]]));

        $this->assertSame(0, $this->events());
    }

    /**
     * The attempt, not the answer.
     *
     * `first_attempt_at` and `first_contact_at` are separate columns precisely so a team cannot look
     * responsive while nobody has dialled. This alert watches the dialling — holding anybody to
     * «somebody picked up» would page them for the buyer's behaviour rather than their own.
     */
    public function test_an_owner_who_has_not_tried_is_alerted_and_one_who_tried_is_not(): void
    {
        $owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);

        $this->lead(['owner_id' => $owner->id, 'assigned_at' => Carbon::now()->subHours(3)]);

        app(AlertEvaluator::class)->evaluateRule($this->rule('lead_no_contact', ['threshold' => ['minutes' => 60]]));
        $this->assertSame(1, $this->events());

        AlertEvent::query()->delete();
        Lead::query()->withoutGlobalScopes()->update(['first_attempt_at' => Carbon::now()->subHour()]);

        app(AlertEvaluator::class)->evaluateRule($this->rule('lead_no_contact', ['threshold' => ['minutes' => 60]]));
        $this->assertSame(0, $this->events(), 'the attempt was made; the alert is about the attempt');
    }

    public function test_a_promise_past_its_date_is_reported_without_any_window(): void
    {
        // Promised three weeks ago: still overdue today, and no recent-window filter may hide it.
        $this->lead([
            'received_at' => Carbon::now()->subDays(40),
            'next_follow_up_at' => Carbon::now()->subDays(21),
        ]);

        $raised = app(AlertEvaluator::class)->evaluateRule($this->rule('lead_follow_up_overdue'));

        $this->assertSame(1, $raised);
    }

    /**
     * A decision already made is not an overdue list.
     *
     * Won, lost and invalid leads carry old follow-up dates by nature. Counting them hands a team a
     * queue they can never clear, and a queue nobody can clear is a queue nobody reads.
     */
    public function test_a_closed_lead_is_not_an_outstanding_promise(): void
    {
        foreach ([LeadStage::Won, LeadStage::Lost, LeadStage::Invalid] as $stage) {
            $this->lead([
                'status' => $stage->value,
                'next_follow_up_at' => Carbon::now()->subDays(5),
                'owner_id' => null,
            ]);
        }

        app(AlertEvaluator::class)->evaluateRule($this->rule('lead_follow_up_overdue'));
        app(AlertEvaluator::class)->evaluateRule($this->rule('lead_unassigned', ['threshold' => ['minutes' => 1]]));

        $this->assertSame(0, $this->events());
    }

    /**
     * Another client's leads are another client's problem.
     *
     * A rule naming a project must never count outside it — the alert would name this client and
     * describe somebody else's backlog, which is worse than no alert at all.
     */
    public function test_a_project_rule_counts_only_that_project(): void
    {
        $other = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'name' => 'Other', 'status' => 'active',
        ]);

        $this->lead();
        $this->lead(['project_id' => $other->id]);

        app(AlertEvaluator::class)->evaluateRule($this->rule('lead_unassigned', ['threshold' => ['minutes' => 60]]));

        $event = AlertEvent::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame(1, $event->context['count'], 'the rule counted a lead outside its own project');
        $this->assertSame((string) $this->project->id, (string) $event->project_id);
    }

    /**
     * The engine's cooldown applies, because the engine is the engine.
     *
     * If these had been given their own dispatcher this would pass and mean nothing; it passes
     * because nothing new was written.
     */
    public function test_the_same_backlog_does_not_alert_twice_inside_the_cooldown(): void
    {
        $this->lead();
        $rule = $this->rule('lead_unassigned', ['threshold' => ['minutes' => 60]]);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule), 'the cooldown was not honoured');
    }

    /**
     * The rule a person actually creates, through the endpoint they create it with.
     *
     * Every test above builds an `AlertRule` directly, and all of them passed while the API refused
     * the rule with «حقل غير معروف في الحد: minutes» — the controller's threshold whitelist knew
     * `days`, `pct` and `ratio`, and an SLA is counted in minutes. A follow-up rule could be
     * evaluated perfectly and never be created, which is the whole feature missing behind a green
     * suite. Found by creating one in the browser.
     */
    public function test_the_endpoint_accepts_a_follow_up_rule_with_its_sla_in_minutes(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);

        $role = Role::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid(),
        ]);
        $this->seed(PermissionSeeder::class);
        $role->givePermissionTo(...Permission::whereIn('key', ['alerts.view', 'alerts.manage'])->pluck('key')->all());
        $user->assignRole($role);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/alerts/rules', [
                'type' => 'lead_unassigned',
                'name' => 'SLA — بلا مسؤول خلال ساعة',
                'threshold' => ['minutes' => 60],
                'cooldown_minutes' => 720,
                'severity' => 'warning',
                'channels' => ['in_app'],
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'lead_unassigned')
            ->assertJsonPath('data.threshold.minutes', 60);
    }

    /** No lead identity travels in an alert: a channel is not the product. */
    public function test_an_alert_names_no_lead(): void
    {
        $this->lead(['name' => 'نورة العتيبي', 'email' => 'noura@buyer.test']);

        app(AlertEvaluator::class)->evaluateRule($this->rule('lead_unassigned', ['threshold' => ['minutes' => 60]]));

        $event = AlertEvent::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $blob = (string) json_encode($event->context, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('نورة العتيبي', $blob);
        $this->assertStringNotContainsString('noura@buyer.test', $blob);
    }
}
