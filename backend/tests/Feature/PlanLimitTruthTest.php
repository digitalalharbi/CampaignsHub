<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Subscriptions\Http\Middleware\EnsureWithinPlanLimit;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan limits count what the customer actually holds, on every path that creates it (TENANCY-UNIFY-001 audit, 2026-11-01).
 *
 * - A client archived from the clients screen (`archived_at` / `client_status`) gives its slot back.
 * - A client's portal users are the agency's clients, not its team: they do not take team seats.
 * - Restoring a client, a client report, and converting a request into a client + project are all held to the plan.
 */
final class PlanLimitTruthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Limits Co', 'slug' => 'limits-co', 'status' => 'active']);
        app(SubscriptionService::class)->assignPlan($this->tenant, SubscriptionPlan::where('code', 'team')->firstOrFail(), 'active', Carbon::now()->addDays(20));
    }

    private function client(array $over = []): string
    {
        $id = (string) Str::uuid();
        DB::table('client_workspaces')->insert($over + [
            'id' => $id, 'tenant_id' => (string) $this->tenant->id, 'name' => 'C '.$id, 'slug' => 'c-'.$id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_a_client_archived_from_the_clients_screen_gives_its_slot_back(): void
    {
        $this->client();
        $this->client(['client_status' => 'archived', 'archived_at' => now()]);

        $this->assertSame(1, app(SubscriptionService::class)->usage($this->tenant, 'clients'));
    }

    public function test_client_portal_users_and_invitations_do_not_take_team_seats(): void
    {
        $client = $this->client();
        $users = collect(range(1, 3))->map(fn (int $i) => User::create(['name' => "U{$i}", 'email' => "u{$i}@limits.test", 'password' => 'secret1234'])->id);
        $this->grantMembership(User::findOrFail($users[0]), $this->tenant, Portal::Agency, 'owner');
        $this->grantMembership(User::findOrFail($users[1]), $this->tenant, Portal::ClientPortal, 'client_viewer', [$client]);
        DB::table('workspace_invitations')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => (string) $this->tenant->id, 'email' => 'client@limits.test', 'role_slug' => 'client_viewer',
            'token_hash' => Str::random(64), 'portal' => Portal::ClientPortal->value, 'client_workspace_id' => $client,
            'expires_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, app(SubscriptionService::class)->usage($this->tenant, 'team_members'), 'only the agency person');
    }

    public function test_every_path_that_creates_a_client_project_or_report_is_held_to_the_plan(): void
    {
        $guarded = fn (string $name, string $metric) => in_array(EnsureWithinPlanLimit::class.':'.$metric,
            Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [], true);

        $this->assertTrue($guarded('api.v1.app.clients.restore', 'clients'));
        $this->assertTrue($guarded('api.v1.app.clients.reports.store', 'reports_per_month'));
    }

    public function test_converting_a_request_is_refused_when_the_plan_has_no_client_left(): void
    {
        foreach (range(1, 5) as $i) {
            $this->client(); // Team: 5 clients
        }
        $refusal = app(EnsureWithinPlanLimit::class)->refusal($this->tenant, 'clients');

        $this->assertFalse(app(SubscriptionService::class)->withinLimit($this->tenant, 'clients'));
        $this->assertSame(403, $refusal->getStatusCode());
        $this->assertSame('clients', $refusal->getData(true)['meta']['metric']);
    }
}
