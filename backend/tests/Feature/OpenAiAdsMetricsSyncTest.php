<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-OPENAI-001 §18 — the figures, and the one distinction the whole product rests on.
 *
 * «The provider did not report conversions» and «the provider reported no conversions» are different
 * answers. Everything downstream — the availability states on a card, the «لم تُرسل المنصة هذا
 * المؤشر» note on a report, the decision not to put a zero in front of a client — is built on the
 * first never being written as the second.
 *
 * `OpenAiAdsConnectorTest` proves the connector does not collapse them. This proves the PERSISTENCE
 * does not, which is a different claim: a row written with `conversions = 0` because the column is
 * not nullable is the same lie arriving by another route.
 */
final class OpenAiAdsMetricsSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acc', 'slug' => 'acc-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'openai_ads',
            tokens: new OAuthTokens('sk-ads-live-0000beef', null, null),
            connectionName: 'ChatGPT Ads',
            credentialType: 'api_key',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'openai_ads',
            'account_type' => 'ad_account',
            'external_id' => 'acct_live_1',
            'name' => 'Acme Riyadh',
            'currency' => 'SAR',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $this->account->id,
            'provider' => 'openai_ads',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);
    }

    /** The figures the provider did send arrive, in the account's own currency. */
    public function test_reported_figures_persist(): void
    {
        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 120.5, 'impressions' => 10000, 'clicks' => 250, 'conversions' => 12,
        ]);

        $this->runStructureSync();
        $this->runMetricsSync();

        $rows = DB::table('daily_metrics')->where('metric_date', '2026-09-01')->get();

        $this->assertGreaterThan(0, $rows->count(), 'nothing persisted for a day the provider reported');

        $byKey = $rows->keyBy('metric_key');
        $this->assertEqualsWithDelta(120.5, (float) $byKey['spend']->value, 0.001);
        $this->assertEqualsWithDelta(10000, (float) $byKey['impressions']->value, 0.001);
        $this->assertEqualsWithDelta(250, (float) $byKey['clicks']->value, 0.001);
        $this->assertEqualsWithDelta(12, (float) $byKey['conversions']->value, 0.001);
    }

    /**
     * **A figure the provider never sent has no row.**
     *
     * Not a row holding zero. The absence IS the answer, and a surface reading these rows must be
     * able to tell «we were not told» from «it was nothing» — which it can only do if nothing was
     * written.
     */
    public function test_a_figure_the_provider_never_sent_has_no_row(): void
    {
        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 90.0, 'impressions' => 4000, 'clicks' => 60,
            // No conversions key at all, and revenue explicitly null.
            'revenue' => null,
        ]);

        $this->runStructureSync();
        $this->runMetricsSync();

        $keys = DB::table('daily_metrics')->where('metric_date', '2026-09-01')->pluck('metric_key')->all();

        $this->assertContains('spend', $keys);
        $this->assertNotContains('conversions', $keys, 'an unreported conversion count was written as a figure');
        $this->assertNotContains('revenue', $keys, 'an unreported revenue was written as a figure');
    }

    /** And a zero the provider DID report is kept, because it is an answer. */
    public function test_a_reported_zero_is_kept(): void
    {
        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 90.0, 'impressions' => 4000, 'clicks' => 60, 'conversions' => 0,
        ]);

        $this->runStructureSync();
        $this->runMetricsSync();

        $row = DB::table('daily_metrics')->where('metric_date', '2026-09-01')->where('metric_key', 'conversions')->first();

        $this->assertNotNull($row, 'a real zero was dropped, which reads as «not reported»');
        $this->assertEqualsWithDelta(0.0, (float) $row->value, 0.001);
    }

    /**
     * **The same window twice is the same figures.**
     *
     * A metrics sync re-runs constantly — a sweep, a backfill, a reconnect. A second run that
     * inserted rather than updated would double every figure on the dashboard with nothing anywhere
     * saying why.
     */
    public function test_a_second_sync_of_the_same_window_changes_nothing(): void
    {
        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 120.5, 'impressions' => 10000, 'clicks' => 250, 'conversions' => 12,
        ]);

        $this->runStructureSync();
        $this->runMetricsSync();
        $first = DB::table('daily_metrics')->orderBy('metric_key')->get(['metric_key', 'value'])->toArray();

        // Not vacuous: two empty sets are also «equal», and this test passed that way while the
        // sync was persisting nothing at all.
        $this->assertNotSame([], $first, 'the first sync persisted nothing, so this proves nothing');

        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 120.5, 'impressions' => 10000, 'clicks' => 250, 'conversions' => 12,
        ]);
        $this->runStructureSync();
        $this->runMetricsSync();

        $second = DB::table('daily_metrics')->orderBy('metric_key')->get(['metric_key', 'value'])->toArray();

        $this->assertEquals($first, $second, 'a repeated sync changed the figures');
    }

    /**
     * Structure first, exactly as production does it.
     *
     * An insights row names a campaign, and a figure whose campaign this product has never heard of
     * has nowhere to hang. The sweep syncs structure before metrics for the same reason.
     */
    private function runStructureSync(): void
    {
        app()->call([new SyncAccountStructureJob($this->account->id), 'handle']);
    }

    private function runMetricsSync(): void
    {
        app()->call([
            new SyncAccountMetricsJob($this->account->id, '2026-09-01', '2026-09-07'),
            'handle',
        ]);
    }

    /** @param array<string, mixed> $row */
    private function providerReports(array $row): void
    {
        Http::fake(function ($request) use ($row) {
            $url = $request->url();

            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [$row]]);
            }

            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1', 'name' => 'Acme Riyadh', 'currency' => 'SAR']);
        });
    }
}
