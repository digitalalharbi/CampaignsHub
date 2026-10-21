<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUDGET-HIERARCHY-001 — the campaign rung states the PLATFORM's own ceiling and its place in the hierarchy.
 *
 * The plan is what somebody typed here; the limit the platform enforces is what actually stops
 * delivery. The account rung already paced against it, the campaign rung could not state it, and a
 * reader had to open each platform. The same arithmetic as the account cap: a lifetime budget counts
 * whole, a daily budget once per day of the window; two currencies refuse as a sum; «not sent» is
 * null, never zero. `providers` and `account_ids` let the screen drill project → platform → account
 * → campaign without a query per rung.
 */
final class BudgetProviderLimitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    private function campaign(float $budget): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'C-'.uniqid(), 'objective' => 'sales', 'status' => 'active',
            'total_budget' => $budget, 'budget_currency' => 'SAR',
        ]);
    }

    /** A real ad account on a real connection — the foreign keys a provider campaign hangs from. */
    private function account(string $provider): string
    {
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => $provider, 'connection_name' => $provider.'-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected']);

        return (string) ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'act-'.uniqid(), 'name' => 'A', 'status' => 'active', 'currency' => 'SAR',
        ])->id;
    }

    private function linked(UnifiedCampaign $c, string $provider, string $accountId, ?float $daily, ?float $lifetime, ?string $currency = 'SAR'): void
    {
        DB::table('external_campaigns')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'unified_campaign_id' => $c->id, 'external_account_id' => $accountId, 'provider' => $provider,
            'external_id' => 'x-'.uniqid(), 'name' => 'X', 'status' => 'active',
            'daily_budget' => $daily, 'lifetime_budget' => $lifetime, 'currency' => $currency,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function spend(UnifiedCampaign $c, float $value): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'meta', 'metric_key' => 'spend',
            'metric_date' => '2026-08-02', 'value' => $value, 'project_currency' => 'SAR',
        ]);
    }

    /** @return array<array-key, array<string, mixed>> keyed by campaign id */
    private function rows(): array
    {
        // A ten-day window.
        return collect(app(MetricsAggregator::class)->forProjects([(string) $this->project->id])->budgetPacing(
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-10'),
            Carbon::parse('2026-08-05'),
        ))->keyBy('campaign_id')->all();
    }

    public function test_the_provider_limit_is_lifetime_plus_daily_times_the_window_and_the_placement_travels(): void
    {
        $c = $this->campaign(10_000);
        $this->spend($c, 3_000);
        $a1 = $this->account('meta');
        $a2 = $this->account('meta');
        $this->linked($c, 'meta', $a1, null, 8_000.0);
        $this->linked($c, 'meta', $a2, 100.0, null);

        $row = $this->rows()[(string) $c->id];

        // 8,000 lifetime + 100 a day over ten days.
        $this->assertEqualsWithDelta(9_000.0, (float) $row['provider_limit'], 0.01);
        $this->assertSame('SAR', $row['provider_limit_currency']);
        $this->assertSame('stated', $row['provider_limit_basis']);
        $this->assertSame(['meta'], $row['providers']);
        $this->assertEqualsCanonicalizing([$a1, $a2], $row['account_ids']);
    }

    /** A ceiling nobody sent is null, never zero — zero reads as «nothing left to spend». */
    public function test_a_limit_nobody_sent_is_null_and_says_so(): void
    {
        $c = $this->campaign(10_000);
        $this->spend($c, 3_000);
        $this->linked($c, 'tiktok', $this->account('tiktok'), null, null);

        $row = $this->rows()[(string) $c->id];

        $this->assertNull($row['provider_limit']);
        $this->assertSame('not_sent', $row['provider_limit_basis']);
        $this->assertSame(['tiktok'], $row['providers']);
    }

    /** Two currencies are not one sum: refused, and the reason named, like every money figure here. */
    public function test_a_limit_in_two_currencies_is_refused(): void
    {
        $c = $this->campaign(10_000);
        $this->spend($c, 3_000);
        $this->linked($c, 'meta', $this->account('meta'), null, 8_000.0, 'SAR');
        $this->linked($c, 'google', $this->account('google'), null, 500.0, 'USD');

        $row = $this->rows()[(string) $c->id];

        $this->assertNull($row['provider_limit']);
        $this->assertSame('mixed_currency', $row['provider_limit_basis']);
        $this->assertEqualsCanonicalizing(['meta', 'google'], $row['providers']);
    }

    /** A campaign linked to no provider campaign has no place in the hierarchy and no limit — stated, not invented. */
    public function test_an_unlinked_campaign_has_no_placement(): void
    {
        $c = $this->campaign(10_000);
        $this->spend($c, 3_000);

        $row = $this->rows()[(string) $c->id];

        $this->assertNull($row['provider_limit']);
        $this->assertSame('not_sent', $row['provider_limit_basis']);
        $this->assertSame([], $row['providers']);
        $this->assertSame([], $row['account_ids']);
    }
}
