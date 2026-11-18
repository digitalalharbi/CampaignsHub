<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * OBJECTIVE-RECLASSIFY-RUN-001 — the Owner-approved reclassification, dry run first, counts only.
 */
final class ReclassifyObjectivesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'rc-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'google',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'google',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'google', 'account_type' => 'ad_account', 'external_id' => 'g-1', 'name' => 'G', 'status' => 'active',
        ]);
    }

    public function test_the_dry_run_prints_the_moves_and_writes_nothing_then_the_run_writes_them(): void
    {
        $pmax = $this->campaign('PMax', 'PERFORMANCE_MAX', 'MAXIMIZE_CONVERSION_VALUE');
        $manual = $this->campaign('PMax by hand', 'PERFORMANCE_MAX', 'MAXIMIZE_CONVERSION_VALUE');
        $manual->forceFill(['objective' => 'leads', 'objective_source' => 'manual'])->save();
        $stays = $this->campaign('Search manual CPC', 'SEARCH', 'MANUAL_CPC');

        $this->assertSame(0, Artisan::call('objectives:reclassify', ['--dry-run' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('DRY RUN (rolled back)', $out);
        $this->assertStringContainsString('google | other → sales | 1', $out);
        $this->assertStringContainsString('google | other → other | 1', $out);
        $this->assertStringNotContainsString('PMax', $out, 'counts only — never a campaign name');
        $this->assertSame('other', $pmax->fresh()->objective, 'the dry run wrote nothing');

        $this->assertSame(0, Artisan::call('objectives:reclassify'));
        $this->assertStringContainsString('RUN (committed)', Artisan::output());
        $this->assertSame('sales', $pmax->fresh()->objective);
        $this->assertSame('other', $stays->fresh()->objective);
        $this->assertSame('leads', $manual->fresh()->objective, 'a manual objective never changes');
        $this->assertSame('manual', $manual->fresh()->objective_source);
    }

    private function campaign(string $name, string $channel, string $bidding): UnifiedCampaign
    {
        $this->holdingTenant((string) $this->tenant->id);
        $unified = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => 'other',
        ]);
        ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(), 'unified_campaign_id' => $unified->id,
            'provider' => 'google', 'external_id' => 'e-'.uniqid(), 'name' => $name, 'status' => 'active',
            'objective' => $channel, 'raw' => ['raw' => ['campaign' => ['advertisingChannelType' => $channel, 'biddingStrategyType' => $bidding]]],
        ]);

        return $unified;
    }
}
