<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Platform\Jobs\QueueHeartbeatJob;
use App\Domains\Platform\Services\OperationalReadiness;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * PROD-001 — the deployment's own health, told honestly.
 *
 * The failure this suite exists for is not a wrong number; it is a monitor that answers a question
 * nobody asked. `/ready` said `ready` because the database was up, while the queue worker had been
 * dead for a day and every report sat at «قيد المعالجة». The product would have gone on serving
 * yesterday's figures under a «محدَّث» badge until a customer complained.
 *
 * So the two checks are separated by the decision each one drives. `/ready` is the load balancer's
 * question — can THIS node serve — and must not fail because a worker elsewhere died, since pulling
 * healthy web nodes turns a delayed report into an outage. The operator's status endpoint is where
 * a stopped background process is visible and pageable.
 */
final class OperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Cache::forget(OperationalReadiness::SCHEDULER_HEARTBEAT);
        Cache::forget(OperationalReadiness::QUEUE_HEARTBEAT);
    }

    // ── readiness answers the load balancer's question, and only that ─────────────────────────

    /**
     * A dead worker does not take the web node out of rotation.
     *
     * Serious, and not a reason to stop serving: the pages still render, the API still answers, and
     * the one thing that is broken is fixed by restarting a process somewhere else. Failing readiness
     * here would convert a delayed report into an outage — and would do it at exactly the moment the
     * operator most needs the product reachable to diagnose it.
     */
    public function test_readiness_does_not_fail_because_a_background_process_is_dead(): void
    {
        // No heartbeat from anything.
        $this->getJson('/api/v1/ready')->assertOk()->assertJsonPath('data.status', 'ready');
    }

    /**
     * Redis is probed only when something actually uses it.
     *
     * The previous probe pinged Redis unconditionally, so a deployment on the database queue and
     * database sessions — supported, and what `config/queue.php` still defaults to — reported itself
     * unready for a dependency it does not have, and would never have entered rotation.
     */
    public function test_readiness_does_not_probe_redis_when_nothing_is_configured_to_use_it(): void
    {
        config([
            'queue.default' => 'database',
            'cache.default' => 'database',
            'session.driver' => 'database',
        ]);

        $checks = $this->getJson('/api/v1/ready')->assertOk()->json('data.checks');

        $this->assertArrayHasKey('database', $checks);
        $this->assertArrayNotHasKey('redis', $checks);
    }

    /** …and IS probed when it is the queue driver. */
    public function test_readiness_probes_redis_when_it_is_the_queue_driver(): void
    {
        config(['queue.default' => 'redis']);

        $this->assertArrayHasKey('redis', $this->getJson('/api/v1/ready')->json('data.checks'));
    }

    // ── the operator's status tells the truth about the background processes ──────────────────

    /** Both heartbeats fresh → healthy, and the endpoint answers 200 so a monitor needs no parsing. */
    public function test_a_deployment_with_both_heartbeats_reads_healthy(): void
    {
        app(OperationalReadiness::class)->markScheduler();
        app(OperationalReadiness::class)->markQueue();

        $res = $this->actingAs($this->platformOwner(), 'sanctum')
            ->getJson('/api/v1/admin/operational-status')->assertOk();

        $this->assertSame('healthy', $res->json('data.verdict'));
        $this->assertSame('up', $res->json('data.processes.queue.state'));
        $this->assertSame('up', $res->json('data.processes.scheduler.state'));
    }

    /**
     * A stale queue heartbeat is `degraded`, and the HTTP status says so without being read.
     *
     * The whole point: a monitor pointed at this URL pages somebody when the worker dies, which is
     * the event that silently broke every report before this existed.
     */
    public function test_a_stale_queue_heartbeat_is_reported_as_down_and_answers_503(): void
    {
        app(OperationalReadiness::class)->markScheduler();
        app(OperationalReadiness::class)->markQueue(Carbon::now()->subMinutes(OperationalReadiness::HEARTBEAT_GRACE_MINUTES + 5));

        $res = $this->actingAs($this->platformOwner(), 'sanctum')
            ->getJson('/api/v1/admin/operational-status')->assertStatus(503);

        $this->assertSame('degraded', $res->json('data.verdict'));
        $this->assertSame('down', $res->json('data.processes.queue.state'));
        // And it says what the reader is supposed to do about it.
        $this->assertStringContainsString('horizon', $res->json('data.processes.queue.fix'));
        $this->assertNotEmpty($res->json('data.processes.queue.why_it_matters_ar'));
    }

    /**
     * «Never seen» is not «down», and does not page anybody.
     *
     * A deployment ninety seconds old, or one whose cache was just flushed, has no heartbeat yet.
     * Treating that as a dead worker means paging on every release, and a monitor that cries wolf at
     * every deploy is one nobody reads by the third week.
     */
    public function test_a_deployment_that_has_not_beaten_yet_is_unverified_rather_than_down(): void
    {
        $res = $this->actingAs($this->platformOwner(), 'sanctum')
            ->getJson('/api/v1/admin/operational-status')->assertOk();

        $this->assertSame('unverified', $res->json('data.verdict'));
        $this->assertSame('never_seen', $res->json('data.processes.queue.state'));
    }

    /** The heartbeat is written by the JOB, on the worker — dispatching alone proves nothing. */
    public function test_the_queue_heartbeat_is_written_when_the_job_is_actually_processed(): void
    {
        $readiness = app(OperationalReadiness::class);

        $this->assertSame('never_seen', $readiness->status()['processes']['queue']['state']);

        (new QueueHeartbeatJob)->handle($readiness);

        $this->assertSame('up', $readiness->status()['processes']['queue']['state']);
    }

    /**
     * The status names queue depths and failure counts across every tenant, so it is the platform
     * operator's and nobody else's — not even a workspace owner holding every permission theirs can
     * give.
     */
    public function test_a_tenant_administrator_cannot_read_the_operational_status(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);

        $this->actingAs($this->tenantAdmin($tenant), 'sanctum')
            ->getJson('/api/v1/admin/operational-status')
            ->assertForbidden();
    }

    public function test_the_operational_status_refuses_an_anonymous_caller(): void
    {
        $this->getJson('/api/v1/admin/operational-status')->assertUnauthorized();
    }

    // ── Horizon ──────────────────────────────────────────────────────────────────────────────

    /**
     * The queue dashboard is the platform operator's alone.
     *
     * Horizon shows job payloads, and a payload here carries a tenant id, a client's name, a store's
     * external id — one screen listing every tenant's work. Horizon's own scaffolding ships an empty
     * email allow-list, which is safe and unusable; this asks the `is_platform_admin` flag instead.
     */
    public function test_only_the_platform_operator_may_open_the_queue_dashboard(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);

        $this->assertTrue(Gate::forUser($this->platformOwner())->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($this->tenantAdmin($tenant))->allows('viewHorizon'));
    }

    /**
     * The dashboard itself refuses, over HTTP, outside `local`.
     *
     * The gate assertion above tests the rule; this tests that the rule is actually WIRED to the
     * route. Horizon's middleware is `Gate::check('viewHorizon') || app()->environment('local')`, so
     * on a developer machine `/horizon` is open by design and a check performed there proves nothing.
     * The test environment is `testing`, which is precisely the gate-only path production takes.
     */
    public function test_the_queue_dashboard_refuses_anyone_but_the_operator_over_http(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);

        $this->get('/horizon')->assertForbidden();
        $this->actingAs($this->tenantAdmin($tenant))->get('/horizon')->assertForbidden();
        $this->actingAs($this->platformOwner())->get('/horizon')->assertSuccessful();
    }

    /**
     * The supervisor watches the reports queue as well as the default one.
     *
     * Horizon's published default watches `default` alone, and this application dispatches report
     * generation onto `reports`. An installation that replaced `queue:work` with Horizon — the entire
     * point of installing it — would have left every report at «قيد المعالجة» forever, with a
     * dashboard beside it reporting a perfectly healthy queue.
     */
    public function test_the_horizon_supervisor_watches_every_queue_this_application_uses(): void
    {
        $queues = config('horizon.defaults.supervisor-1.queue');

        $this->assertContains('reports', $queues);
        $this->assertContains('default', $queues);
        // And it keeps the retry/timeout guarantees the runbook's `queue:work` line always gave.
        $this->assertSame(3, config('horizon.defaults.supervisor-1.tries'));
        $this->assertSame(120, config('horizon.defaults.supervisor-1.timeout'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function platformOwner(): User
    {
        $user = User::create([
            'name' => 'Platform Owner', 'email' => 'ops-owner@platform.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    /** A workspace owner holding every permission their tenant can give — still not the operator. */
    private function tenantAdmin(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Tenant Admin', 'email' => 'ops-admin@tenant.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'slug' => 'admin-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::App, role: 'owner',
        ));

        return $user;
    }
}
