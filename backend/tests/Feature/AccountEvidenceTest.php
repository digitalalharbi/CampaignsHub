<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Status\AccountEvidence;
use App\Domains\Metrics\Rates\CurrencyRateFeed;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** KARAM-SYNC-HONESTY-001 — the stored facts behind the two chips, and the chip clearing when the rate arrives. */
final class AccountEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_withheld_money_is_counted_per_account_and_clears_when_the_rate_is_recorded(): void
    {
        $tenant = Tenant::create(['name' => 'E', 'slug' => 'ev-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'ev-c-'.uniqid(), 'mode' => 'managed', 'default_currency' => 'USD']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $account = (string) Uuid::uuid4();
        $other = (string) Uuid::uuid4();
        foreach ([[$account, '2026-11-08'], [$account, '2026-11-09'], [$other, '2026-11-09']] as [$acc, $day]) {
            DB::table('daily_metrics')->insert([
                'id' => (string) Uuid::uuid4(), 'tenant_id' => $tenant->id, 'project_id' => $project->id,
                'external_account_id' => $acc, 'external_campaign_id' => (string) Uuid::uuid4(),
                'provider' => 'google', 'metric_key' => 'spend', 'metric_date' => $day,
                'value' => null, 'original_amount' => 12.5, 'original_currency' => $acc === $account ? 'EUR' : 'GBP',
                'project_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $facts = AccountEvidence::load((string) $tenant->id, [$account, $other]);
        $this->assertSame(2, $facts[$account]['withheld_rows']);
        $this->assertSame('EUR', $facts[$account]['withheld_currency']);
        $this->assertSame('GBP', $facts[$other]['withheld_currency']);
        $this->assertTrue($facts[$account]['has_delivery']);
        $this->assertSame(0, $facts[$account]['ad_sets']);

        app(CurrencyRateFeed::class)->record('EUR', 'USD', 1.1, Carbon::parse('2026-11-08'), 'test');

        $after = AccountEvidence::load((string) $tenant->id, [$account, $other]);
        $this->assertSame(0, $after[$account]['withheld_rows']);
        $this->assertSame(1, $after[$other]['withheld_rows'], 'another currency is still waiting');
    }

    public function test_an_account_of_another_tenant_is_never_counted(): void
    {
        $mine = Tenant::create(['name' => 'E2', 'slug' => 'ev2-'.uniqid(), 'status' => 'active']);
        $theirs = Tenant::create(['name' => 'E3', 'slug' => 'ev3-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $theirs->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'ev3-c-'.uniqid(), 'mode' => 'managed', 'default_currency' => 'USD']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $account = (string) Uuid::uuid4();
        DB::table('daily_metrics')->insert([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => $theirs->id, 'project_id' => $project->id,
            'external_account_id' => $account, 'external_campaign_id' => (string) Uuid::uuid4(),
            'provider' => 'google', 'metric_key' => 'spend', 'metric_date' => '2026-11-09',
            'value' => null, 'original_amount' => 5, 'original_currency' => 'EUR', 'project_currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, AccountEvidence::load((string) $mine->id, [$account])[$account]['withheld_rows']);
    }
}
