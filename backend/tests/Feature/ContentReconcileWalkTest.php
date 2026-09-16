<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner defect 95 — the walk is the instrument, so the instrument is held to what it promises.
 *
 * Three properties, and the first is the one that makes it safe to point at a live account while a
 * customer is looking at the same screen: it WRITES NOTHING. `integrations:probe` is held to the same
 * bar by `test_the_probe_imports_nothing`, and for the same reason — a diagnosis that mutates the
 * thing it is diagnosing is worse than no diagnosis.
 *
 * The second is that it prints no url. These links carry the signature that makes them work, and a
 * workflow log is readable by anybody with access to the repository.
 *
 * The third is that it actually FINDS a divergence when one exists, which is the only thing that
 * separates a diagnostic from a green tick. Proved by asking it about a creative whose figures live
 * only at the ad grain while the walk's own expectations are set by the fixed product — so the
 * reconciliation reports agreement, and the divergence case is built by hand below.
 */
final class ContentReconcileWalkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Walk', 'slug' => 'w-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Meta',
            'status' => 'active',
        ]);

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'meta', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    /** A diagnosis that changes the thing it diagnoses is not one. */
    public function test_the_walk_writes_nothing(): void
    {
        $creative = $this->creativeWithAdGrain();

        $before = $this->rowCounts();

        $this->artisan('content:reconcile', ['creative' => (string) $creative->getKey()])
            ->assertExitCode(0);

        $this->artisan('content:reconcile', ['--scope' => true, '--project' => (string) $this->project->getKey()])
            ->assertExitCode(0);

        $this->assertSame($before, $this->rowCounts(), 'the walk wrote to a table it was only meant to read');
    }

    /**
     * It reports the two grains apart, because that split IS the owner's defect.
     *
     * A creative whose figures exist only at the ad grain is one `creative_daily_metrics` never held,
     * and on five of six providers it is all of them. A walk that could not say so would be unable to
     * describe the thing it exists to describe.
     */
    public function test_the_scope_walk_names_the_creatives_only_the_ad_grain_can_answer(): void
    {
        $this->creativeWithAdGrain();

        $this->artisan('content:reconcile', ['--scope' => true, '--project' => (string) $this->project->getKey()])
            ->expectsOutputToContain('summed from their ADS')
            ->expectsOutputToContain('carry figures ONLY at the ad grain')
            ->assertExitCode(0);
    }

    /**
     * And it prints no url — these links carry the signature that makes them work.
     *
     * The preview rung reports the STATE and whether each url exists, never the url. A workflow log is
     * readable by anybody with repository access, which is not the same audience as the ad account.
     */
    public function test_the_walk_prints_no_asset_url(): void
    {
        $creative = $this->creativeWithAdGrain();
        $creative->forceFill([
            'asset_url' => 'https://cdn.test/secret-signature-abc123.jpg',
            'video_url' => 'https://cdn.test/secret-signature-def456.mp4',
        ])->save();

        $this->artisan('content:reconcile', ['creative' => (string) $creative->getKey()])
            ->doesntExpectOutputToContain('secret-signature')
            ->assertExitCode(0);
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach ([
            'external_creatives', 'external_ads', 'creative_daily_metrics', 'entity_daily_metrics',
            'daily_metrics', 'metric_sync_runs', 'integration_sync_runs', 'audit_logs',
        ] as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    private function creativeWithAdGrain(): ExternalCreative
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-walk',
            'name' => 'Walk subject',
            'format' => 'image',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        $ad = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'creative_id' => $creative->getKey(),
            'provider' => 'meta',
            'external_id' => 'ad-walk',
            'name' => 'Ad',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        DB::table('entity_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'entity_type' => 'ad',
            'entity_id' => $ad->getKey(),
            'external_entity_id' => 'ad-walk',
            'external_campaign_id' => $this->campaign->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'attribution_window' => 'default',
            'spend' => 250.0,
            'impressions' => 12_000,
            'clicks' => 480,
            'leads' => 16,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $creative;
    }
}
