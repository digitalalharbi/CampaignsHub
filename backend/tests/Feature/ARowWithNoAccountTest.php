<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Access\LeadVisibility;
use App\Domains\CRM\Models\Lead;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «No account» is not «a deselected account», and it is not one answer either: see `BoundAccountVisibility`. These pin what each table's
 * NULL means, so a later change to the predicate has to say which of them it means to change.
 */
final class ARowWithNoAccountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $workspace = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
    }

    public function test_the_tables_that_carry_figures_and_orders_cannot_hold_one(): void
    {
        foreach (['daily_metrics', 'external_campaigns', 'commerce_orders', 'commerce_products', 'commerce_customers', 'commerce_abandoned_carts'] as $table) {
            $nullable = DB::table('information_schema.columns')
                ->where('table_schema', 'public')->where('table_name', $table)->where('column_name', 'external_account_id')->value('is_nullable');

            $this->assertSame('NO', $nullable, "{$table}: a NULL account would be hidden by BoundAccountVisibility::apply, and this assumes it cannot exist");
        }
    }

    public function test_a_lead_typed_in_by_a_person_stays_visible(): void
    {
        $lead = Lead::withoutGlobalScopes()->forceCreate(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Typed in', 'external_account_id' => null]);

        $this->assertTrue(LeadVisibility::isBoundToItsProject($lead));
    }

    public function test_a_sync_run_with_no_account_vouches_for_no_accounts_freshness(): void
    {
        $run = new MetricSyncRun;
        $run->forceFill(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'meta', 'status' => 'success', 'window_start' => '2026-01-01', 'window_end' => '2026-01-02'])->save();

        $this->assertSame(0, BoundAccountVisibility::apply(MetricSyncRun::withoutGlobalScopes()->whereKey($run->getKey()), 'metric_sync_runs')->count());
    }

    /** Listed, because it hangs off no campaign and so named no account to deselect; not launchable, because it cannot be shown to belong to the account it would be sent to. */
    public function test_a_creative_with_no_account_is_listed_but_is_not_offered_to_a_launch(): void
    {
        $creative = ExternalCreative::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'meta', 'external_creative_id' => 'c-1', 'name' => 'estimated',
        ]);
        $row = fn () => ExternalCreative::withoutGlobalScopes()->whereKey($creative->getKey());

        $this->assertSame(1, BoundAccountVisibility::applyThroughCampaign($row(), 'external_creatives.external_campaign_id', 'external_creatives.project_id')->count());
        $this->assertSame(0, BoundAccountVisibility::apply($row(), 'external_creatives')->count());
    }
}
