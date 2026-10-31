<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Platform\Models\SupportSession;
use App\Domains\Platform\Support\SupportAccess;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SUPERADMIN-SUPPORT-ACCESS-001 — «الدخول للدعم» through the real browser path (a stateful SPA session), not a test shortcut.
 *
 * A visit pins the admin to ONE tenant, read-only, for 30 minutes, after re-entering their own password; every start, end, page and
 * refusal lands in that tenant's audit trail. Writes, secrets, money and exports are refused before any controller. Another tenant's
 * rows stay invisible. With no live visit, nothing about the admin changes.
 */
final class SupportAccessTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $acme;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->acme = $this->tenantWithProject('Acme', 'Acme launch');
        $this->other = $this->tenantWithProject('Other', 'Other secret project');
        app(TenantContext::class)->forget();
    }

    private function tenantWithProject(string $name, string $project): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        Workspace::create(['tenant_id' => $tenant->id, 'name' => 'Default', 'slug' => 'default']);
        $client = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $client->id, 'name' => $project, 'status' => 'active']);

        return $tenant;
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Operator', 'email' => 'op-'.uniqid().'@platform.test', 'password' => 'operator-pass-1234']);
        $u->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $u;
    }

    /** Sign in as the admin from a browser; the same cookie session carries the visit. */
    private function browser(User $admin): self
    {
        $this->withCredentials()->withHeaders($this->spa)
            ->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'operator-pass-1234'])->assertOk();

        return $this;
    }

    private function startVisit(?Tenant $tenant = null)
    {
        return $this->postJson('/api/v1/admin/tenants/'.($tenant ?? $this->acme)->id.'/support-session', [
            'reason' => 'Customer cannot find their report', 'current_password' => 'operator-pass-1234',
        ]);
    }

    public function test_only_a_platform_admin_starts_a_visit_and_only_with_their_own_password_and_a_reason(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'operator-pass-1234']);
        $this->actingAs($user, 'sanctum')->startVisit()->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->browser($this->admin());
        $this->postJson('/api/v1/admin/tenants/'.$this->acme->id.'/support-session', ['reason' => 'x', 'current_password' => 'operator-pass-1234'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/admin/tenants/'.$this->acme->id.'/support-session', ['reason' => 'Customer cannot find their report', 'current_password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertSame(0, SupportSession::query()->count());
    }

    public function test_a_visit_sees_that_workspace_only_and_says_so_to_the_spa(): void
    {
        $this->browser($this->admin());
        $this->startVisit()->assertOk()->assertJsonPath('data.landing_path', '/agency');

        $memberships = $this->getJson('/api/v1/auth/memberships')->assertOk()->json('data');
        $this->assertCount(1, $memberships['memberships']);
        $this->assertSame((string) $this->acme->id, $memberships['current']['tenant']['id']);
        $this->assertSame('/agency', $memberships['destination']);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.support.tenant_name', 'Acme')->assertJsonPath('data.user.support.mode', 'read');

        $names = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertContains('Acme launch', $names);
        $this->assertNotContains('Other secret project', $names, 'another tenant stays invisible');
    }

    public function test_writes_secrets_and_money_are_refused_and_every_refusal_is_in_the_tenants_audit_trail(): void
    {
        $admin = $this->admin();
        $this->browser($admin);
        $this->startVisit()->assertOk();

        $this->postJson('/api/v1/projects', ['name' => 'Injected'])->assertForbidden()->assertJsonPath('code', 'support_read_only');
        $this->deleteJson('/api/v1/projects/'.Project::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->value('id'))->assertForbidden();
        $this->getJson('/api/v1/billing/quotes')->assertForbidden()->assertJsonPath('code', 'support_read_only');
        $this->getJson('/api/v1/subscriptions/current')->assertForbidden()->assertJsonPath('code', 'support_read_only');
        $this->assertSame(1, Project::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->count(), 'nothing was written or deleted');

        $refusals = AuditLog::withoutGlobalScopes()->where('action', 'support.refused')->get();
        $this->assertGreaterThanOrEqual(4, $refusals->count());
        $this->assertTrue($refusals->every(fn ($a) => (string) $a->tenant_id === (string) $this->acme->id && (int) $a->user_id === (int) $admin->id));
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'support.session.started')->where('tenant_id', $this->acme->id)->count());
    }

    public function test_ending_the_visit_returns_the_admin_to_the_console_and_the_workspace_is_gone(): void
    {
        $this->browser($this->admin());
        $this->startVisit()->assertOk();

        $this->deleteJson('/api/v1/admin/support-session')->assertOk()->assertJsonPath('data.return_path', '/admin/tenants');

        $me = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.user');
        $this->assertArrayHasKey('support', $me);
        $this->assertNull($me['support']);
        $this->assertCount(0, $this->getJson('/api/v1/auth/memberships')->json('data.memberships'));
        $this->assertNotNull(SupportSession::query()->sole()->ended_at);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'support.session.ended')->where('tenant_id', $this->acme->id)->count());
    }

    public function test_a_visit_ends_by_itself_after_thirty_minutes(): void
    {
        $this->browser($this->admin());
        $this->startVisit()->assertOk();

        Carbon::setTestNow(Carbon::now()->addMinutes(31));
        $me = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.user');
        $this->assertArrayHasKey('support', $me);
        $this->assertNull($me['support']);
        $this->assertSame('expired', SupportSession::query()->sole()->ended_reason);
        Carbon::setTestNow();
    }

    public function test_a_visit_to_one_workspace_replaces_the_previous_one(): void
    {
        $this->browser($this->admin());
        $this->startVisit($this->acme)->assertOk();
        $this->startVisit($this->other)->assertOk();

        $this->assertSame((string) $this->other->id, $this->getJson('/api/v1/auth/memberships')->json('data.current.tenant.id'));
        $this->assertSame(1, SupportSession::query()->whereNull('ended_at')->count());
    }

    /** A visit is held per browser session: the same admin in another browser keeps theirs (and parallel E2E runs keep theirs). */
    public function test_a_new_visit_replaces_only_this_browsers_visit_not_the_admins_others(): void
    {
        $admin = $this->admin();
        $support = app(SupportAccess::class);

        $elsewhere = $support->start($admin, $this->acme, 'Other browser visit', null);
        $here = $support->start($admin, $this->other, 'This browser visit', null);
        $this->assertNull($elsewhere->fresh()->ended_at, 'the other browser keeps its visit');

        $support->start($admin, $this->acme, 'This browser again', null, $here->id);
        $this->assertSame('replaced', $here->fresh()->ended_reason);
        $this->assertNull($elsewhere->fresh()->ended_at);
    }
}
