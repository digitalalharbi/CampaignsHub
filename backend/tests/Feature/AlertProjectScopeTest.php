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
 * ANALYTICS-FILTER-TRUTH-001 — the alerts leg of the propagation clause.
 *
 * The ledger is tenant-scoped and nothing narrows it to the project the reader is in. An agency
 * holding ten clients opened one client's workspace and read ten clients' alerts on one screen,
 * with nothing on any row to say whose it was — and the page's own empty state promised the
 * opposite in as many words: «no active rule has fired for THIS PROJECT».
 *
 * A tenant-wide alert is a real thing and must survive the narrowing: a token expiring belongs to a
 * connection rather than to a project, and `AlertEvaluator::tokenExpiries()` writes `project_id`
 * null on purpose. Dropping those would trade a leak for a silence, which is the worse of the two —
 * an alert nobody is shown is an alert nobody acts on.
 */
final class AlertProjectScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $here;

    private Project $elsewhere;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->here = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Here', 'status' => 'active']);
        $this->elsewhere = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Elsewhere', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    private function event(?string $projectId, string $severity = 'warning', string $status = 'open'): AlertEvent
    {
        $rule = AlertRule::create(['tenant_id' => $this->tenant->id, 'project_id' => $projectId,
            'type' => 'sync_failure', 'name' => 'R', 'active' => true]);

        return AlertEvent::create(['tenant_id' => $this->tenant->id, 'project_id' => $projectId, 'rule_id' => $rule->id,
            'type' => 'sync_failure', 'dedup_key' => hash('sha256', (string) Str::uuid()), 'status' => $status,
            'severity' => $severity, 'last_triggered_at' => Carbon::now()]);
    }

    /** @return array{0:array<int,string>,1:array<string,mixed>} the ids on the page, and the meta beside them */
    private function ask(array $params = []): array
    {
        $r = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/alerts/events?'.http_build_query($params))
            ->assertOk();

        return [array_column((array) $r->json('data'), 'id'), (array) $r->json('meta')];
    }

    public function test_another_projects_alert_is_not_shown_inside_this_project(): void
    {
        $mine = $this->event($this->here->id);
        $theirs = $this->event($this->elsewhere->id);

        [$ids] = $this->ask(['project' => $this->here->id]);

        $this->assertContains((string) $mine->id, $ids);
        $this->assertNotContains((string) $theirs->id, $ids, 'another project’s alert was listed inside this one');
    }

    /**
     * A token expiring is not a project's problem, and it must not disappear into one.
     *
     * `tokenExpiries()` writes `project_id` null deliberately — the connection it names may feed
     * several projects or none. Narrowing on equality alone would hide the alert on every screen a
     * person actually opens, which is how a sync goes dark for a week.
     */
    public function test_an_account_wide_alert_survives_the_narrowing(): void
    {
        $wide = $this->event(null);

        [$ids] = $this->ask(['project' => $this->here->id]);

        $this->assertContains((string) $wide->id, $ids);
    }

    /**
     * The badges are computed over the SAME scope as the list, or they are a lie in the loudest place.
     *
     * This endpoint already learned that once: `meta.counts` is deliberately computed over the whole
     * ledger rather than over the capped page. Narrowing the page and leaving the counts whole would
     * reintroduce the identical defect from the other end — «3 open» above a list showing one.
     */
    public function test_the_counts_describe_the_narrowed_scope(): void
    {
        $this->event($this->here->id, 'critical');
        $this->event($this->elsewhere->id);
        $this->event($this->elsewhere->id);

        [$ids, $meta] = $this->ask(['project' => $this->here->id]);

        $this->assertCount(1, $ids);
        $this->assertSame(1, $meta['counts']['open']);
        $this->assertSame(1, $meta['counts']['open_critical']);
        $this->assertSame(1, $meta['total']);
    }

    /** Asked for nothing in particular, it still answers for the whole tenant — the ledger has not moved. */
    public function test_without_a_project_the_whole_tenant_is_answered(): void
    {
        $mine = $this->event($this->here->id);
        $theirs = $this->event($this->elsewhere->id);

        [$ids] = $this->ask();

        $this->assertContains((string) $mine->id, $ids);
        $this->assertContains((string) $theirs->id, $ids);
    }

    /**
     * An unknown project narrows to nothing rather than falling back to everything.
     *
     * «An empty filtered scope NEVER falls back to unfiltered» is this requirement's own rule, and a
     * project id from another tenant is the case it protects: the ledger is tenant-scoped, so the
     * answer is the account-wide rows and nothing else — never the whole tenant under a chip naming
     * somebody else's project.
     */
    public function test_an_unknown_project_does_not_fall_back_to_the_whole_tenant(): void
    {
        $this->event($this->here->id);
        $this->event($this->elsewhere->id);

        [$ids] = $this->ask(['project' => (string) Str::uuid()]);

        $this->assertSame([], $ids);
    }
}
