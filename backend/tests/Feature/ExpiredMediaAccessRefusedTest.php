<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Http\Controllers\CreativeAnalysisController;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Support\PresentationAudience;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * An expired link whose ad account the platform refuses is not «needs a fresh sync» — no sync helps
 * until the owner re-authorises. The operator is told so; a client never is.
 */
final class ExpiredMediaAccessRefusedTest extends TestCase
{
    use RefreshDatabase;

    private ExternalCreative $creative;

    private ProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'R', 'slug' => 'r-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $workspace = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        $this->connection = app(TokenVault::class)->open(tenantId: $tenant->id, provider: 'meta', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta');
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $this->connection->getKey(), 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'A', 'status' => 'active',
        ]);
        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'external_id' => 'c1', 'name' => 'C', 'status' => 'active',
        ]);
        $this->creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_creative_id' => '1201', 'name' => 'Still', 'format' => 'image',
            'asset_url' => 'https://scontent.xx.fbcdn.net/old.jpg', 'asset_expires_at' => now()->subDay(),
            'source_type' => 'api', 'is_demo' => false,
        ]);
    }

    public function test_an_operator_is_told_the_account_refuses_access(): void
    {
        $this->refusedRun('(#200) Ad account owner has NOT grant ads_management or ads_read permission');
        app(PresentationAudience::class)->forOperator();

        $preview = app(CreativePresenter::class)->preview($this->creative);

        $this->assertSame('expired', $preview['state']);
        $this->assertTrue($preview['access_refused'] ?? false);
        $this->assertStringContainsString('refuses access', (string) $preview['note_en']);
    }

    public function test_a_client_is_never_told_about_the_connection(): void
    {
        $this->refusedRun('(#200) Ad account owner has NOT grant ads_management or ads_read permission');

        $preview = app(CreativePresenter::class)->preview($this->creative);

        $this->assertSame('expired', $preview['state']);
        $this->assertArrayNotHasKey('access_refused', $preview);
        $this->assertStringNotContainsString('refuses', (string) $preview['note_en']);
    }

    public function test_a_reauthorised_connection_is_no_longer_called_refused(): void
    {
        $this->refusedRun('(#200) permission denied');
        $this->connection->forceFill(['last_health_check_at' => now()->addMinute()])->save();
        app(PresentationAudience::class)->forOperator();

        $this->assertArrayNotHasKey('access_refused', app(CreativePresenter::class)->preview($this->creative));
    }

    public function test_a_throttle_is_not_a_refusal(): void
    {
        $this->refusedRun('(#17) User request limit reached — too many calls');
        app(PresentationAudience::class)->forOperator();

        $this->assertArrayNotHasKey('access_refused', app(CreativePresenter::class)->preview($this->creative));
    }

    public function test_the_operator_content_controller_is_what_names_the_audience(): void
    {
        $this->assertFalse(app(PresentationAudience::class)->isOperator(), 'the default audience must be the client');

        app(CreativeAnalysisController::class);

        $this->assertTrue(app(PresentationAudience::class)->isOperator());
    }

    private function refusedRun(string $error): void
    {
        $this->connection->forceFill(['last_health_check_at' => now()->subDays(2)])->save();

        (new IntegrationSyncRun)->forceFill([
            'tenant_id' => $this->creative->tenant_id, 'provider_connection_id' => $this->connection->getKey(),
            'type' => 'structure', 'status' => 'failed', 'error' => $error,
            'started_at' => now()->subHour(), 'finished_at' => now()->subHour(),
        ])->save();
    }
}
