<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EMAIL-SETTINGS-DEPTH-001 — the delivery LOG: what was actually sent, and what failed.
 *
 * The records already existed and nothing listed them. `digest_sends` carries status, reason,
 * attempts and last_error; `mail_deliveries` carries the transactional side. A settings screen that
 * shows only «last send: 08:04» answers «did the last one work?» and not «has this person been
 * getting them», which is the question somebody asks when a client says they never see the report.
 *
 * Three properties are asserted, and the third is the one that keeps the log honest:
 *
 *   1. Both ledgers appear, newest first — reading one would show «nothing sent» to somebody
 *      receiving a digest every morning.
 *   2. A FAILED send is in the log, with its reason. A log of successes is a log that cannot answer
 *      the only question anybody opens it for.
 *   3. Another tenant's sends are not in it.
 */
final class EmailDeliveryLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'dl-'.uniqid(), 'status' => 'active']);
        $this->other = Tenant::create(['name' => 'B', 'slug' => 'dl2-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'dl-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    /** Both ledgers, newest first. */
    public function test_the_log_reads_both_ledgers_newest_first(): void
    {
        $this->digestSend($this->tenant->id, 'sent', at: '2026-08-20 08:00:00');
        $this->mailDelivery('sent', at: '2026-08-25 09:00:00');

        $rows = $this->log();

        $this->assertCount(2, $rows);
        $this->assertSame('transactional', $rows[0]['source']);
        $this->assertSame('digest', $rows[1]['source']);
    }

    /**
     * A failure is in the log WITH its reason.
     *
     * A log of successes cannot answer the question anybody opens it for — «the client says they
     * never get it» — and a failure with no reason is only marginally better.
     */
    public function test_a_failed_send_appears_with_its_reason(): void
    {
        $this->digestSend($this->tenant->id, 'failed', reason: 'no_recipients', at: '2026-08-26 08:00:00');

        $rows = $this->log();

        $this->assertSame('failed', $rows[0]['status']);
        $this->assertSame('no_recipients', $rows[0]['reason']);
    }

    /** Another workspace's sends are not this workspace's business. */
    public function test_another_tenants_sends_are_not_listed(): void
    {
        $this->digestSend($this->other->id, 'sent', at: '2026-08-27 08:00:00');
        $this->digestSend($this->tenant->id, 'sent', at: '2026-08-20 08:00:00');

        $rows = $this->log();

        $this->assertCount(1, $rows);
        $this->assertSame('2026-08-20', substr((string) $rows[0]['at'], 0, 10));
    }

    /** Nothing sent yet is «nothing sent yet» — an empty list, never an error. */
    public function test_an_empty_log_is_empty_rather_than_broken(): void
    {
        $this->assertSame([], $this->log());
    }

    /** @return list<array<string,mixed>> */
    private function log(): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/settings/notifications/deliveries')
            ->assertOk()
            ->json('data');
    }

    private function digestSend(string $tenantId, string $status, ?string $reason = null, string $at = '2026-08-20 08:00:00'): void
    {
        DB::table('digest_sends')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $this->owner->id,
            'kind' => 'daily',
            // Unique per (user, kind, period) — the ledger's own guard against double-sending.
            'period_key' => substr($at, 0, 10),
            'status' => $status,
            'reason' => $reason,
            'attempts' => 1,
            'sent_at' => $status === 'sent' ? $at : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function mailDelivery(string $status, string $at): void
    {
        DB::table('mail_deliveries')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'recipient' => $this->owner->email,
            'kind' => 'invitation',
            'template' => 'invitation',
            // What the transport looked like at the moment of the attempt — «failed on the log
            // driver» and «failed on live SMTP» are different incidents.
            'transport' => 'smtp',
            'status' => $status,
            'sent_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
