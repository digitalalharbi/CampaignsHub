<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\ReclassifyCampaignObjectives;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * OBJECTIVE-NORMALIZATION-002 — repairing the campaigns that already hold a platform's own word.
 *
 * Every campaign on the production Snapchat account carries `SALES` in the column that is supposed to
 * hold a {@see CampaignObjective} value. The import that put it there is fixed, but a fixed import
 * only helps rows written afterwards.
 *
 * The two claims that matter are that it repairs, and that it can be run again — the last inline
 * backfill in this repository was not idempotent, and a second pass wrote over preserved money.
 */
final class ReclassifyCampaignObjectivesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'R', 'slug' => 'r-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act_snap',
            'name' => 'Snap',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    /** The production case: Snapchat's own word, standing in the canonical column. */
    public function test_a_raw_platform_objective_is_reclassified_from_the_linked_campaign(): void
    {
        $unified = $this->unified('SALES', source: 'platform');
        $this->external($unified, 'SALES');

        $result = app(ReclassifyCampaignObjectives::class)->execute();

        $unified->refresh();

        $this->assertSame(CampaignObjective::Sales->value, $unified->objective);
        $this->assertSame('SALES', $unified->objective_platform_value, 'The platform said SALES and that is worth keeping.');
        $this->assertSame(1, $result['reclassified']);
    }

    /**
     * The guard is the column's own validity, so a second pass finds nothing.
     *
     * Asserted by running it twice and reading the counts — «examined: 0» is the only proof that a
     * repeat cannot rewrite what the first pass settled.
     */
    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $unified = $this->unified('SALES', source: 'platform');
        $this->external($unified, 'SALES');

        app(ReclassifyCampaignObjectives::class)->execute();
        $after = app(ReclassifyCampaignObjectives::class)->execute();

        $unified->refresh();

        $this->assertSame(['examined' => 0, 'reclassified' => 0, 'unclassified' => 0], $after);
        $this->assertSame(CampaignObjective::Sales->value, $unified->objective);
    }

    /** A person's classification is never overwritten, whatever the platform now says. */
    public function test_a_manual_objective_is_left_exactly_as_the_person_set_it(): void
    {
        $unified = $this->unified('NOT_A_CANONICAL_VALUE', source: 'manual');
        $this->external($unified, 'SALES');

        $result = app(ReclassifyCampaignObjectives::class)->execute();

        $unified->refresh();

        $this->assertSame('NOT_A_CANONICAL_VALUE', $unified->objective);
        $this->assertSame(0, $result['examined']);
    }

    /**
     * A word nobody has mapped becomes `other` — a canonical «not classified» — and the platform's
     * own string survives, because «wrong about this» and «never said» are different problems for the
     * person who has to settle it.
     */
    public function test_an_unmappable_objective_becomes_other_without_losing_what_the_platform_said(): void
    {
        $unified = $this->unified('PERFORMANCE_MAX', source: 'platform');
        $this->external($unified, 'PERFORMANCE_MAX');

        $result = app(ReclassifyCampaignObjectives::class)->execute();

        $unified->refresh();

        $this->assertSame(CampaignObjective::Other->value, $unified->objective);
        $this->assertSame('PERFORMANCE_MAX', $unified->objective_platform_value);
        $this->assertSame(1, $result['unclassified']);
    }

    /** A campaign already holding a canonical value is not examined at all. */
    public function test_a_correct_campaign_is_never_touched(): void
    {
        $unified = $this->unified(CampaignObjective::Awareness->value, source: 'platform');
        $this->external($unified, 'SALES');

        $result = app(ReclassifyCampaignObjectives::class)->execute();

        $unified->refresh();

        $this->assertSame(CampaignObjective::Awareness->value, $unified->objective);
        $this->assertSame(0, $result['examined']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function unified(string $objective, string $source): UnifiedCampaign
    {
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Product Offers',
            'objective' => CampaignObjective::Other->value,
            'status' => 'active',
            'budget_currency' => 'USD',
            'platforms' => ['snapchat'],
        ]);

        // `forceFill`, because the raw value is exactly what mass assignment would reject and what
        // production actually holds.
        $campaign->forceFill(['objective' => $objective, 'objective_source' => $source])->save();

        return $campaign;
    }

    private function external(UnifiedCampaign $unified, string $objective): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->id,
            'unified_campaign_id' => $unified->id,
            'provider' => 'snapchat',
            'external_id' => 'cmp-'.uniqid(),
            'name' => 'Product Offers',
            'status' => 'active',
            'objective' => $objective,
        ]);
    }
}
