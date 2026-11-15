<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** NOTIF-V2-ENGINE-001 — the contract schema exists, and both logs refuse UPDATE and DELETE at the database level (spec §4). */
final class EngineSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_contract_table_exists(): void
    {
        foreach ([
            'notification_plan_features', 'notification_event_catalog', 'workspace_entitlement_states',
            'workspace_notification_settings', 'notification_external_recipients', 'notification_subscriptions',
            'notification_subscription_projects', 'notification_outbox', 'notification_delivery_log', 'notification_subscription_log',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_the_delivery_log_cannot_be_updated_or_deleted(): void
    {
        $id = (string) Str::uuid();
        DB::table('notification_delivery_log')->insert([
            'id' => $id, 'tenant_id' => (string) Str::uuid(), 'delivery_id' => (string) Str::uuid(), 'reference' => 'N-TEST',
            'event_key' => 'digest.periodic', 'scope' => 'external', 'channel' => 'email', 'recipient_kind' => 'external',
            'status' => 'sent',
        ]);

        $this->assertRefused(fn () => DB::table('notification_delivery_log')->where('id', $id)->update(['status' => 'failed']));
        $this->assertRefused(fn () => DB::table('notification_delivery_log')->where('id', $id)->delete());
        $this->assertSame('sent', DB::table('notification_delivery_log')->where('id', $id)->value('status'));
    }

    public function test_the_subscription_log_cannot_be_updated_or_deleted(): void
    {
        $id = (string) Str::uuid();
        DB::table('notification_subscription_log')->insert(['id' => $id, 'tenant_id' => (string) Str::uuid(), 'action' => 'confirmed']);

        $this->assertRefused(fn () => DB::table('notification_subscription_log')->where('id', $id)->update(['action' => 'cancelled']));
        $this->assertRefused(fn () => DB::table('notification_subscription_log')->where('id', $id)->delete());
        $this->assertSame(1, DB::table('notification_subscription_log')->where('id', $id)->count());
    }

    private function assertRefused(callable $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('The log accepted a change.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
