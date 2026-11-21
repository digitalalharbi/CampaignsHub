<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\ImportExternalCampaigns;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CAMPAIGN-NAME-PLATFORM-ONLY-001 — a campaign's visible name is its platform's name; no id is ever appended.
 */
final class CampaignNameCarriesNoPlatformIdTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $provider): ExternalAccount
    {
        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider.'-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'act_'.$provider, 'name' => $provider, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return $account;
    }

    /** @param list<array<string,mixed>> $rows */
    private function import(ExternalAccount $account, array $rows): void
    {
        app(ImportExternalCampaigns::class)->execute($account, SyncResult::of($rows), (string) $this->project->id);
    }

    private function unifiedOf(string $externalId): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->findOrFail(
            ExternalCampaign::withoutGlobalScopes()->where('external_id', $externalId)->value('unified_campaign_id'));
    }

    /** Two platform campaigns with one name in one project: two visible campaigns, both named exactly as on the platform. */
    public function test_two_platform_campaigns_sharing_a_name_keep_the_plain_name(): void
    {
        $snap = $this->account('snapchat');
        $this->import($snap, [
            ['id' => '03682f53-16ac-4b3d-a983-143a7efe364c', 'name' => '26Aug 2026- ReSano Power Spot Pro', 'status' => 'ACTIVE'],
            ['id' => '9b1c2d3e-0000-4b3d-a983-143a7efe0001', 'name' => '26Aug 2026- ReSano Power Spot Pro', 'status' => 'ACTIVE'],
        ]);

        $first = $this->unifiedOf('03682f53-16ac-4b3d-a983-143a7efe364c');
        $second = $this->unifiedOf('9b1c2d3e-0000-4b3d-a983-143a7efe0001');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('26Aug 2026- ReSano Power Spot Pro', $first->name);
        $this->assertSame('26Aug 2026- ReSano Power Spot Pro', $second->name);
        $this->assertStringNotContainsString(' · ', $first->name.$second->name);
    }

    /** A re-sync of the same platform campaign never renames it with an id either. */
    public function test_a_resync_keeps_the_plain_name(): void
    {
        $snap = $this->account('snapchat');
        $rows = [['id' => 's1', 'name' => 'Spot', 'status' => 'ACTIVE'], ['id' => 's2', 'name' => 'Spot', 'status' => 'ACTIVE']];
        $this->import($snap, $rows);
        $this->import($snap, $rows);

        $this->assertSame(['Spot', 'Spot'], UnifiedCampaign::withoutGlobalScopes()->where('project_id', $this->project->id)->orderBy('name')->pluck('name')->all());
    }
}
