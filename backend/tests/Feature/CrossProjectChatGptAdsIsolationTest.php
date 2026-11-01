<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-002 — the 2026-11-01 incident, end to end, in the two projects it happened in.
 *
 * «Riwaq» is the project whose public report showed another advertiser's ChatGPT Ads spend;
 * «Avenue» is the advertiser whose campaigns they were. The history is reproduced as it sat in
 * Production: the campaigns of Avenue's account were also filed under Riwaq's account, with figures,
 * in Riwaq's project. What this holds, through the same endpoint a client opens:
 *
 *   - before the repair Riwaq's report carries Avenue's spend (the defect, stated so the test cannot
 *     pass on an empty fixture);
 *   - after the Owner-approved quarantine Riwaq's report carries NONE of it, and Avenue's report is
 *     byte-for-byte what it was;
 *   - the same holds for any other project, because the fixture is generic: nothing names a project.
 */
final class CrossProjectChatGptAdsIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $riwaq;

    private Project $avenue;

    private ExternalAccount $riwaqAccount;

    private ExternalAccount $avenueAccount;

    private UnifiedCampaign $riwaqOwn;

    private UnifiedCampaign $riwaqLeaked;

    private UnifiedCampaign $avenueCampaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->riwaq = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'Riwaq', 'status' => 'active']);
        $this->avenue = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'Avenue', 'status' => 'active']);

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(), provider: 'openai_ads',
            tokens: new OAuthTokens('sk-ads-live-avenue-0000', null, null), connectionName: 'ChatGPT Ads', credentialType: 'api_key',
        );

        $this->riwaqAccount = $this->account($connection->getKey(), 'adacct_riwaq');
        $this->avenueAccount = $this->account($connection->getKey(), 'adacct_avenue');

        foreach ([[$this->riwaqAccount, $this->riwaq], [$this->avenueAccount, $this->avenue]] as [$account, $project]) {
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'project_id' => $project->getKey(),
                'external_account_id' => $account->getKey(), 'provider' => 'openai_ads', 'purpose' => 'advertising', 'is_active' => true,
            ]);
        }

        // Avenue's own campaign, filed in Avenue — and the SAME provider campaign filed in Riwaq. Riwaq also has a campaign of its own.
        $this->avenueCampaign = $this->campaign($this->avenue, $this->avenueAccount, 'cmp_avenue', 187.73);
        $this->riwaqLeaked = $this->campaign($this->riwaq, $this->riwaqAccount, 'cmp_avenue', 187.73);
        $this->riwaqOwn = $this->campaign($this->riwaq, $this->riwaqAccount, 'cmp_riwaq', 12.5);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        // The key on the connection is Avenue's: it answers for Avenue's account and lists Avenue's campaign.
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_avenue', 'name' => 'n']], 'has_more' => false]);
            }

            return Http::response(['id' => 'adacct_avenue', 'object' => 'ad_account', 'name' => 'Avenue']);
        });
    }

    public function test_riwaqs_report_carries_no_avenue_spend_after_the_approved_repair_and_avenues_is_unchanged(): void
    {
        $riwaqBefore = $this->spendOf($this->riwaq, [$this->riwaqOwn, $this->riwaqLeaked]);
        $avenueBefore = $this->payload($this->avenue, [$this->avenueCampaign]);

        $this->assertEqualsWithDelta(200.23, $riwaqBefore, 0.01, 'the fixture no longer reproduces the leak');

        // The Owner names Avenue's account as the owner of cmp_avenue; the plan is read first, then applied.
        $this->assertSame(0, Artisan::call('integrations:account-ownership-audit', ['--dry-run' => true, '--keep-account' => $this->avenueAccount->getKey()]));
        $this->assertSame(0, DB::table('isolation_quarantines')->count(), 'the dry run wrote');
        $this->assertSame(0, Artisan::call('integrations:account-ownership-audit', ['--apply' => true, '--keep-account' => $this->avenueAccount->getKey()]));

        $this->assertEqualsWithDelta(12.5, $this->spendOf($this->riwaq, [$this->riwaqOwn]), 0.01, 'Riwaq lost its own spend or kept Avenue\'s');
        $this->assertSame($avenueBefore, $this->payload($this->avenue, [$this->avenueCampaign]), 'Avenue\'s report changed');

        // The leaked row asked for by name is no longer reachable from Riwaq at all.
        $this->assertSame(0, DB::table('daily_metrics')->where('project_id', $this->riwaq->getKey())->where('unified_campaign_id', $this->riwaqLeaked->getKey())->count());
    }

    /** The invariant, stated for every pair: no project's figures sit under an account of a different provider account's campaign. */
    public function test_no_project_holds_a_campaign_of_a_different_advertisers_account_after_the_repair(): void
    {
        Artisan::call('integrations:account-ownership-audit', ['--apply' => true, '--keep-account' => $this->avenueAccount->getKey()]);

        $copies = DB::table('external_campaigns AS c')
            ->join('external_accounts AS a', 'a.id', '=', 'c.external_account_id')
            ->select('c.provider', 'c.external_id')
            ->groupBy('c.provider', 'c.external_id')
            ->havingRaw('COUNT(DISTINCT a.external_id) > 1')
            ->get()
            ->count();

        $this->assertSame(0, $copies);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────

    private function account(string $connection, string $providerId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection, 'provider' => 'openai_ads',
            'account_type' => 'ad_account', 'external_id' => $providerId, 'name' => $providerId, 'status' => 'active', 'discovered_at' => now(),
        ]);
    }

    private function campaign(Project $project, ExternalAccount $account, string $providerCampaign, float $spend): UnifiedCampaign
    {
        $unified = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $project->getKey(),
            'name' => $providerCampaign.'-'.Str::random(4), 'status' => 'active', 'objective' => 'sales',
        ]);
        $external = (string) Str::uuid();

        DB::table('external_campaigns')->insert([
            'id' => $external, 'tenant_id' => $this->tenant->getKey(), 'project_id' => $project->getKey(),
            'unified_campaign_id' => $unified->getKey(), 'external_account_id' => $account->getKey(), 'provider' => 'openai_ads',
            'external_id' => $providerCampaign, 'name' => $providerCampaign, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([['spend', $spend], ['impressions', 1000.0], ['clicks', 40.0]] as [$key, $value]) {
            DB::table('daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $project->getKey(),
                'external_account_id' => $account->getKey(), 'external_campaign_id' => $external, 'unified_campaign_id' => $unified->getKey(),
                'provider' => 'openai_ads', 'metric_key' => $key, 'metric_date' => now()->subDays(2)->toDateString(),
                'value' => $value, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $unified;
    }

    /** @param list<UnifiedCampaign> $campaigns */
    private function spendOf(Project $project, array $campaigns): float
    {
        $row = collect($this->payload($project, $campaigns)['platforms'] ?? [])->firstWhere('provider', 'openai_ads');

        return (float) ($row['spend'] ?? 0);
    }

    /**
     * What the client's public link answers — the same endpoint, the same share service.
     *
     * @param  list<UnifiedCampaign>  $campaigns
     * @return array<string, mixed>
     */
    private function payload(Project $project, array $campaigns): array
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $project->getKey(), 'name' => 'R', 'type' => 'live', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(), 'data' => [],
        ]);

        [, $raw] = app(ShareService::class)->create($report, [
            'mode' => 'live', 'form' => null,
            'scope' => [
                'project_id' => (string) $project->getKey(),
                'campaign_ids' => array_map(static fn (UnifiedCampaign $c): string => (string) $c->getKey(), $campaigns),
                'providers' => ['openai_ads'],
                'earliest' => now()->subDays(30)->toDateString(), 'latest' => now()->toDateString(),
            ],
        ], null);

        $data = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
        // Identifiers of THIS request, not figures: they differ between any two reads of the same report.
        unset($data['generated_at'], $data['report_trace']);

        return $data;
    }
}
