<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\MetaConnectionDiagnosis;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Metrics\Support\NoDataReading;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-NO-DATA-READING-001 — «no_data» means one thing: the provider answered with an empty list.
 *
 * Production, 2026-11-05 (production-diagnostics runs 42120180636 and 42120421829): account «hanka» read
 * `no_data` (raw 0, parsed 0, stored 0) on every half-hour sweep for the window 2026-11-02 → 2026-11-05. The
 * retained body was Meta's own `{"data":[]}`, the same request returned rows for two other accounts in the
 * same sweep, and this account's own figures end on 2026-10-28 — it stopped delivering. Nothing was broken,
 * and nothing on the run said so: «the platform had nothing», «the platform sent rows we could not read» and
 * «the answer was not a list at all» were one word.
 *
 * These pin the three apart. Only the first is `no_data`, and it carries the last day that did deliver.
 */
final class MetaNoDataReadingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        config()->set('ad_platforms.platforms.meta.client_id', 'live-id');
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret');
        Carbon::setTestNow('2026-11-05 22:07:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Production's exact answer for the quiet window, after a window that did deliver. */
    public function test_an_empty_list_after_earlier_delivery_is_no_data_and_names_the_last_day_that_delivered(): void
    {
        $account = $this->boundAccount('act_628943503215859');
        $this->campaign($account, '120249070165420101');

        Http::fake(fn ($request) => Http::response(['data' => str_contains($request->url(), '/insights') && str_contains(urldecode($request->url()), '"since":"2026-10-26"')
            ? [['campaign_id' => '120249070165420101', 'date_start' => '2026-10-28', 'date_stop' => '2026-10-28', 'spend' => '12.50', 'impressions' => '900', 'clicks' => '14']]
            : []]));

        $delivered = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-10-26'), Carbon::parse('2026-11-05'));
        $this->assertSame('success', $delivered->status);

        $quiet = app(AccountMetricsSyncer::class)->sync($account->refresh(), Carbon::parse('2026-11-02'), Carbon::parse('2026-11-05'));

        $this->assertSame('no_data', $quiet->status);
        $this->assertSame(0, $quiet->provider_raw_rows);
        $this->assertNull($quiet->error);
        $this->assertSame(NoDataReading::NO_DELIVERY, $quiet->meta['no_data_reading']);
        $this->assertSame('2026-10-28', $quiet->meta['last_delivery_date']);

        $reading = NoDataReading::for($quiet);
        $this->assertSame(NoDataReading::NO_DELIVERY, $reading['reading']);
        $this->assertSame('2026-10-28', $reading['last_delivery_date']);
        $this->assertStringContainsString('2026-10-28', $reading['sentence']);

        // Nothing was invented for the quiet days, and the day that delivered is still there.
        $this->assertSame(0, DB::table('daily_metrics')->where('external_account_id', $account->id)->where('metric_date', '>=', '2026-11-02')->count());
        $this->assertGreaterThan(0, DB::table('daily_metrics')->where('external_account_id', $account->id)->where('metric_date', '2026-10-28')->count());
    }

    /** The test account: an empty list and nothing ever stored. Still `no_data`, and it does not claim an earlier delivery. */
    public function test_an_empty_list_with_nothing_ever_stored_says_so_rather_than_naming_a_day(): void
    {
        $account = $this->boundAccount('act_1560067839469812');
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-11-02'), Carbon::parse('2026-11-05'));

        $this->assertSame('no_data', $run->status);
        $this->assertSame(NoDataReading::NO_DELIVERY, $run->meta['no_data_reading']);
        $this->assertNull($run->meta['last_delivery_date']);
        $this->assertStringContainsString('never', NoDataReading::for($run)['sentence']);
    }

    /** Rows arrived and none named a campaign: that is not «the platform had nothing», and it needs somebody. */
    public function test_rows_that_could_not_be_read_are_not_filed_as_no_data(): void
    {
        $account = $this->boundAccount('act_1');
        $this->campaign($account, '111');

        Http::fake(fn ($request) => Http::response(['data' => str_contains($request->url(), 'level=campaign')
            ? [['date_start' => '2026-11-03', 'spend' => '5.00', 'impressions' => '100'], ['date_start' => '2026-11-04', 'spend' => '6.00']]
            : []]));

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-11-02'), Carbon::parse('2026-11-05'));

        $this->assertSame('partial_mapping', $run->status);
        $this->assertSame(2, $run->provider_raw_rows);
        $this->assertSame(0, $run->parsed_rows);
        $this->assertSame(0, (int) $run->metrics_upserted);
        $this->assertStringContainsString('2 row(s)', (string) $run->error);
        $this->assertSame('unread_rows', $account->refresh()->last_sync_error_category);
        $this->assertArrayNotHasKey('no_data_reading', (array) $run->meta);
        $this->assertSame(NoDataReading::UNREAD_ROWS, NoDataReading::for($run)['reading']);
        $this->assertSame(0, DB::table('daily_metrics')->where('external_account_id', $account->id)->count());
    }

    /**
     * A 2xx body with no `data` list is not an empty list.
     *
     * An async report handle, a proxy's page, an empty body: each used to be read as «zero rows» and filed
     * as `no_data` — an ordinary quiet day — when nothing had been answered at all.
     */
    public function test_an_answer_that_is_not_a_list_is_a_failed_read_and_never_no_data(): void
    {
        foreach ([['report_run_id' => '6023920149050'], [], ['data' => 'none']] as $body) {
            $account = $this->boundAccount('act_'.random_int(1000, 999999));
            Http::fake(['graph.facebook.com/*' => Http::response($body)]);

            $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-11-02'), Carbon::parse('2026-11-05'));

            $this->assertSame('failed', $run->status, (string) json_encode($body));
            $this->assertStringContainsString('without a `data` list', (string) $run->error);
            $this->assertSame(0, DB::table('daily_metrics')->where('external_account_id', $account->id)->count());
        }
    }

    /** Both read-only diagnoses say which `no_data` this is, from the run and from what is stored. */
    public function test_the_diagnoses_state_the_reading_and_the_last_delivery(): void
    {
        $account = $this->boundAccount('act_628943503215859');
        $campaign = $this->campaign($account, '120249070165420101');
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        // A run recorded BEFORE the reading existed: the diagnosis derives it instead of staying silent.
        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-11-02'), Carbon::parse('2026-11-05'));
        $run->forceFill(['meta' => []])->save();
        $this->storeFigure($account, $campaign, '2026-10-28');

        Artisan::call('integrations:diagnose', ['--provider' => 'meta', '--account' => (string) $account->id]);
        $out = Artisan::output();

        $this->assertStringContainsString('NO DELIVERY IN THE WINDOW', $out);
        $this->assertStringContainsString('last day with a stored figure: 2026-10-28', $out);
        $this->assertStringContainsString('of this CONNECTION', $out);

        $row = collect(app(MetaConnectionDiagnosis::class)->connections())->pluck('accounts')->flatten(1)
            ->firstWhere('external_id', 'act_628943503215859');

        $this->assertSame('no_data', $row['metrics']['status']);
        $this->assertSame(NoDataReading::NO_DELIVERY, $row['metrics']['reading']);
        $this->assertSame('2026-10-28', $row['metrics']['last_delivery_date']);

        Artisan::call('integrations:meta-diagnose');
        $this->assertStringContainsString('metrics=no_data[no_delivery, last delivery 2026-10-28]', Artisan::output());
    }

    private function boundAccount(string $externalId): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('LIVE-TOKEN', 'ads_read', Carbon::now()->addDays(50)), connectionName: 'Meta '.$externalId,
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'external_id' => $externalId, 'name' => $externalId, 'account_type' => 'ad_account', 'status' => 'active', 'currency' => 'USD',
            'last_synced_at' => Carbon::now()->subHour(),
        ]);
        $workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P '.$externalId, 'status' => 'active']);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'client_workspace_id' => $workspace->id,
            'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return $account;
    }

    private function campaign(ExternalAccount $account, string $externalId): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $account->id)->value('project_id'),
            'external_account_id' => $account->id, 'provider' => 'meta', 'external_id' => $externalId,
            'name' => 'Campaign '.$externalId, 'status' => 'paused',
        ]);
    }

    private function storeFigure(ExternalAccount $account, ExternalCampaign $campaign, string $date): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $campaign->project_id, 'connection_id' => $account->provider_connection_id,
            'external_account_id' => $account->id, 'external_campaign_id' => $campaign->id, 'provider' => 'meta',
            'metric_key' => 'impressions', 'metric_date' => $date, 'value' => 900,
        ]);
    }
}
