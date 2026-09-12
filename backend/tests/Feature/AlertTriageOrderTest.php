<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ENTITY-RELEVANCE-ORDERING-001 — the alerts ledger is a TRIAGE queue, and nothing was holding it.
 *
 * ## Why this exists
 *
 * The matrix carried «Content and Alerts listings are not yet ordered by it». Content was examined
 * and deliberately left alone — it is sorted server-side and deterministic — and the Alerts half is
 * stale too: `AlertController::triageOrder()` has ordered by status, then severity, then recency,
 * then id for some time. That is the THIRD «remaining» clause in this ledger found to describe work
 * already done, and each one sends the next execution at something already there.
 *
 * What was true is that nothing guarded the order. A row read from a controller is one refactor away
 * from being read differently, and this one is load-bearing: it is the order a person triages in.
 *
 * ## What the injections actually proved
 *
 * Dropping `nulls last` from the recency clause fails the last case here: Postgres sorts nulls FIRST
 * under `DESC`, so an alert that never recorded a firing time would lead the queue.
 *
 * Removing the `ELSE 3` from the severity `CASE` did NOT fail, and the first draft of this docblock
 * claimed it would — «a CASE with no ELSE would have put those nulls first». That is wrong for the
 * ascending sort this actually uses, where Postgres puts nulls last and the unknown severity lands
 * where it should by accident rather than by instruction. The `ELSE` is worth keeping because it
 * says the intent rather than inheriting it from a default, and this test holds the OUTCOME the
 * controller describes — «a value this application does not recognise is not evidence of urgency» —
 * which stays true however that outcome is reached.
 */
final class AlertTriageOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
            'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@t.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    private function event(string $label, string $severity, string $status = 'open', ?Carbon $at = null): AlertEvent
    {
        $rule = AlertRule::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'type' => 'sync_failure', 'name' => 'R', 'active' => true,
        ]);

        return AlertEvent::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'rule_id' => $rule->id,
            'type' => 'sync_failure', 'dedup_key' => hash('sha256', (string) Str::uuid()),
            'status' => $status, 'severity' => $severity,
            'context' => ['title' => $label],
            'last_triggered_at' => $at ?? Carbon::parse('2026-07-01 10:00:00'),
        ]);
    }

    /** @return list<string> the titles, in the order the ledger returned them */
    private function order(array $params = []): array
    {
        $rows = (array) $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/alerts/events?'.http_build_query($params))
            ->assertOk()
            ->json('data');

        return array_map(static fn (array $r): string => (string) ($r['context']['title'] ?? ''), $rows);
    }

    /** The most serious first — the order a person triages in. */
    public function test_a_critical_alert_leads_a_warning_and_an_info(): void
    {
        $this->event('info', 'info');
        $this->event('critical', 'critical');
        $this->event('warning', 'warning');

        $this->assertSame(['critical', 'warning', 'info'], $this->order(['status' => 'open']));
    }

    /**
     * Status outranks severity: an open warning comes before a snoozed critical.
     *
     * Snoozing is a decision somebody made to stop looking at it until later, and putting it back at
     * the top because it is severe undoes that decision on their behalf.
     */
    public function test_an_open_warning_leads_a_snoozed_critical(): void
    {
        $this->event('snoozed-critical', 'critical', 'snoozed');
        $this->event('open-warning', 'warning');

        $this->assertSame(['open-warning', 'snoozed-critical'], $this->order());
    }

    /** Within one severity, the most recently fired leads — the freshest evidence first. */
    public function test_the_more_recent_of_two_equals_leads(): void
    {
        $this->event('older', 'warning', 'open', Carbon::parse('2026-07-01 09:00:00'));
        $this->event('newer', 'warning', 'open', Carbon::parse('2026-07-05 09:00:00'));

        $this->assertSame(['newer', 'older'], $this->order(['status' => 'open']));
    }

    /**
     * A severity this application does not recognise sorts LAST, never first.
     *
     * The controller states the reason: an unknown value is not evidence of urgency, and letting one
     * lead the queue means a bad write can push real alerts off the screen. Asserted as an OUTCOME:
     * the `ELSE 3` and Postgres's ascending nulls-last both deliver it, and this case does not care
     * which of them did.
     */
    public function test_an_unrecognised_severity_sorts_last(): void
    {
        $this->event('known', 'info');
        $this->event('strange', 'apocalyptic');

        $this->assertSame(['known', 'strange'], $this->order(['status' => 'open']));
    }

    /** And an alert that never recorded a firing time sorts behind one that did. */
    public function test_an_alert_with_no_firing_time_sorts_behind_one_that_has_it(): void
    {
        $withTime = $this->event('timed', 'warning');
        $this->event('untimed', 'warning')->forceFill(['last_triggered_at' => null])->save();

        $this->assertSame(['timed', 'untimed'], $this->order(['status' => 'open']));
        $this->assertNotNull($withTime->last_triggered_at);
    }
}
