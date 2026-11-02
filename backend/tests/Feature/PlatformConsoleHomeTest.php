<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SUPERADMIN-CONSOLE-HOME-001 — the console's home says what needs the operator, across every workspace.
 *
 * Connections that stopped working (refused or expired), open alerts by severity, and the last things done
 * from the console. It names; it does not act: no reconnect, no provider error text, no before/after values.
 */
final class PlatformConsoleHomeTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Operator', 'email' => 'op-'.uniqid().'@platform.test', 'password' => 'secret1234']);
        $u->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $u;
    }

    private function connection(Tenant $tenant, string $name, ?Carbon $expires, string $provider = 'meta'): ProviderConnection
    {
        app(TenantContext::class)->setTenantId((string) $tenant->id);

        return app(TokenVault::class)->open(tenantId: (string) $tenant->id, provider: $provider,
            tokens: new OAuthTokens('T-'.$name, null, $expires), connectionName: $name);
    }

    private function alert(Tenant $tenant, string $severity, string $status = 'open', ?Carbon $snoozedUntil = null): void
    {
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $rule = AlertRule::create(['tenant_id' => $tenant->id, 'type' => 'sync_failure', 'name' => 'R', 'active' => true]);
        AlertEvent::create(['tenant_id' => $tenant->id, 'rule_id' => $rule->id, 'type' => 'sync_failure',
            'dedup_key' => hash('sha256', (string) Str::uuid()), 'status' => $status, 'severity' => $severity,
            'snoozed_until' => $snoozedUntil, 'last_triggered_at' => now()]);
    }

    private function home(): array
    {
        app(TenantContext::class)->forget();

        return $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/overview', $this->spa)->assertOk()->json('data');
    }

    public function test_refused_and_expired_connections_are_listed_across_workspaces_without_the_providers_error_text(): void
    {
        $acme = $this->tenant('Acme');
        $beta = $this->tenant('Beta');
        $refused = $this->connection($acme, 'Project 1', Carbon::now()->addDays(30));
        app(TokenVault::class)->markError($refused, 'Meta returned code 190 for act_998877 (session invalidated)');
        $this->connection($beta, 'Beta Ads', Carbon::now()->subDay(), 'snapchat');
        $this->connection($beta, 'Healthy', Carbon::now()->addDays(40), 'tiktok');

        $home = $this->home();
        $connections = $home['operations']['connections'];

        $this->assertSame(2, $connections['total']);
        $byName = collect($connections['items'])->keyBy('connection_name');
        $this->assertSame(['Beta Ads', 'Project 1'], $byName->keys()->sort()->values()->all());
        $this->assertSame('refused', $byName['Project 1']['reason']);
        $this->assertSame('Acme', $byName['Project 1']['tenant_name']);
        $this->assertSame('expired', $byName['Beta Ads']['reason']);
        $this->assertStringNotContainsString('act_998877', json_encode($home), 'the provider error text stays on the workspace page');

        $attention = collect($home['attention'])->firstWhere('key', 'connections_need_reconnect');
        $this->assertSame(2, $attention['count']);
    }

    public function test_open_alerts_are_counted_by_severity_and_resolved_or_still_snoozed_ones_are_not(): void
    {
        $acme = $this->tenant('Acme');
        $beta = $this->tenant('Beta');
        $this->alert($acme, 'critical');
        $this->alert($acme, 'warning');
        $this->alert($beta, 'warning', 'snoozed', Carbon::now()->subHour());   // snooze over: open again
        $this->alert($beta, 'critical', 'snoozed', Carbon::now()->addDay());   // still snoozed
        $this->alert($beta, 'critical', 'resolved');

        $alerts = $this->home()['operations']['alerts'];

        $this->assertSame(3, $alerts['open']);
        $this->assertSame(['critical' => 1, 'warning' => 2, 'info' => 0], $alerts['by_severity']);
        $this->assertSame(2, $alerts['tenants']);
    }

    public function test_recent_console_activity_lists_platform_and_support_actions_only_and_never_their_values(): void
    {
        $acme = $this->tenant('Acme');
        $op = User::create(['name' => 'Sara Ops', 'email' => 'sara@platform.test', 'password' => 'secret1234']);
        AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $op->id, 'action' => 'platform.tenant.suspended', 'after' => ['secret_marker' => 'XYZ-1']]);
        AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $op->id, 'action' => 'support.session.started']);
        AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $op->id, 'action' => 'campaign.updated']);

        $home = $this->home();
        $recent = collect($home['operations']['recent']);

        $this->assertEqualsCanonicalizing(['platform.tenant.suspended', 'support.session.started'], $recent->pluck('action')->all());
        $this->assertSame('Acme', $recent->first()['tenant_name']);
        $this->assertSame('Sara Ops', $recent->first()['user_name']);
        $this->assertStringNotContainsString('XYZ-1', json_encode($home));
    }

    public function test_a_quiet_platform_reports_zeros_not_absent_keys(): void
    {
        $ops = $this->home()['operations'];

        $this->assertSame(['total' => 0, 'items' => []], $ops['connections']);
        $this->assertSame(0, $ops['alerts']['open']);
        $this->assertSame([], $ops['recent']);
    }

    public function test_only_a_platform_admin_reads_it(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'secret1234']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/overview', $this->spa)->assertForbidden();
    }
}
