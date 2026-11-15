<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Fx\FxEngine;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Spend\SpendLedger;
use App\Domains\Notifications\Engine\Scheduling\DigestRunner;
use App\Domains\Notifications\Engine\Scheduling\SubscriptionScheduler;
use App\Domains\Notifications\External\Digest\DigestDataSource;
use App\Domains\Notifications\External\Digest\MetricsDigestDataSource;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\Feature\NotificationsV2\External\Support\ExternalFixtures;
use Tests\TestCase;

/** Spec §16 «External periods», «Numbers: message == screen after conversion, max difference 0.01», external events. */
final class ExternalPeriodsAndScreenTest extends TestCase
{
    use BuildsWorkspaces;
    use ExternalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExternal();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed>|null the digest intent's period after one runner pass at `$utc` */
    private function runAt(string $s, string $utc): ?array
    {
        DB::table('notification_subscriptions')->where('id', $s)->update(['next_run_at' => Carbon::parse($utc, 'UTC')->subMinute()]);
        DB::table('notification_outbox')->delete();
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        app(DigestRunner::class)->run(Carbon::parse($utc, 'UTC'));
        $row = DB::table('notification_outbox')->where('event_key', 'digest.periodic')->first();

        return $row === null ? null : (array) json_decode((string) $row->payload, true)['period'];
    }

    public function test_this_month_until_yesterday_daily_covers_complete_days_and_skips_weekends(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], ['send_time' => '08:00']);

        $this->assertEquals(['key' => 'this_month_to_yesterday', 'from' => '2026-11-01', 'to' => '2026-11-14'], $this->runAt($s, '2026-11-15 05:00:00'));
        $this->assertSame('2026-11-15', $this->runAt($s, '2026-11-16 05:00:00')['to']);

        // Thursday's run is followed by Sunday's: Friday and Saturday are not work days.
        $sub = app(SubscriptionScheduler::class)->hydrate(DB::table('notification_subscriptions')->where('id', $s)->first());
        $next = app(SubscriptionScheduler::class)->nextRun($sub, Carbon::parse('2026-11-19 05:30:00', 'UTC'));
        $this->assertSame('2026-11-22 08:00', $next?->setTimezone('Asia/Riyadh')->format('Y-m-d H:i'));
    }

    public function test_last_week_weekly_respects_the_week_start(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], ['period_key' => 'last_week', 'frequency' => 'weekly']);
        $this->assertEquals(['key' => 'last_week', 'from' => '2026-11-08', 'to' => '2026-11-14'], $this->runAt($s, '2026-11-15 05:00:00'));
        $sub = app(SubscriptionScheduler::class)->hydrate(DB::table('notification_subscriptions')->where('id', $s)->first());
        $this->assertSame('2026-11-22', app(SubscriptionScheduler::class)->nextRun($sub, Carbon::parse('2026-11-15 06:00:00', 'UTC'))?->setTimezone('Asia/Riyadh')->toDateString());
    }

    public function test_message_equals_screen_after_conversion_within_one_cent(): void
    {
        $this->app->instance(DigestDataSource::class, app(MetricsDigestDataSource::class));
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Store sales', 'objective' => 'sales', 'status' => 'active']);
        // The project's selected ad account (rows of an unselected account are no project's figure: BoundAccountVisibility).
        $connection = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addHour()), connectionName: 'meta-screen');
        $account = (string) ExternalAccount::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act-screen', 'name' => 'Screen account', 'currency' => 'USD', 'timezone' => 'Asia/Riyadh', 'status' => 'active'])->id;
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account, 'provider' => 'meta', 'purpose' => 'ads', 'is_active' => true]);
        foreach (range(1, 14) as $day) {
            foreach (['spend' => 123.457 + $day, 'conversions' => 3 + $day % 4, 'revenue' => 800.0 + $day] as $key => $value) {
                DB::table('daily_metrics')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account,
                    'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id, 'provider' => 'meta', 'metric_key' => $key,
                    'metric_date' => sprintf('2026-11-%02d', $day), 'value' => $value, 'project_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], [], ['display_currency' => 'SAR']);
        app(TenantContext::class)->forget();
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->drain();

        // The screen: the same aggregator scope and FxEngine, read independently.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $scope = app(MetricsAggregator::class)->acrossProjects()->forProjects([$this->project->id]);
        $totals = $scope->totals(Carbon::parse('2026-11-01')->startOfDay(), Carbon::parse('2026-11-14')->endOfDay());
        $screenSar = app(FxEngine::class)->convert((float) $totals['spend'], 'USD', 'SAR', '2026-11-14');
        $this->assertFalse($screenSar->withheld);

        $data = app(MetricsDigestDataSource::class)->project($this->tenant->id, $this->project->id, '2026-11-01', '2026-11-14', 'SAR');
        $this->assertEqualsWithDelta((float) $screenSar->converted, (float) $data['spend'], 0.01);
        // The message IS the approved spend summary, built from the same read the Budgets screen uses (ProjectSpendSummary): its spend is the
        // aggregator's own total for the window, in the project's currency.
        // RELEASE-INTEGRATION — and the headline equals THE spend ledger (N4: the campaigns page, budgets, clients list, dashboards).
        $ledger = app(SpendLedger::class)->read([$this->project->id], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-14'), 'SAR');
        $this->assertTrue($ledger['complete']);
        $this->assertEqualsWithDelta((float) $ledger['amount'], (float) $data['spend'], 0.01);
        $text = (string) $this->sent()[0]['email']->getTextBody();
        $this->assertStringContainsString(number_format((float) $totals['spend'], 0, '.', ','), $text);
        $purchases = (float) $totals['conversions']; // the system's sales result (ObjectiveResult)
        $this->assertEqualsWithDelta($purchases, (float) $data['objectives'][0]['result'], 0.01);
        $this->assertEqualsWithDelta(round((float) $screenSar->converted / $purchases, 2), (float) $data['objectives'][0]['cost_per_result'], 0.01);
    }

    public function test_spend_the_ledger_cannot_convert_withholds_the_message_instead_of_shrinking_its_total(): void
    {
        // RELEASE-INTEGRATION — a EUR account with no EUR rate: the ledger excludes and names it; the message marks it withheld.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $connection = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'google', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addHour()), connectionName: 'google-eur');
        $account = (string) ExternalAccount::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'google',
            'account_type' => 'ad_account', 'external_id' => 'eur-1', 'name' => 'EUR account', 'currency' => 'XEU', 'timezone' => 'Asia/Riyadh', 'status' => 'active'])->id;
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account, 'provider' => 'google', 'purpose' => 'ads', 'is_active' => true]);
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account,
            'external_campaign_id' => (string) Str::uuid(), 'provider' => 'google', 'metric_key' => 'spend', 'metric_date' => '2026-11-05',
            'value' => null, 'original_amount' => 400.0, 'original_currency' => 'XEU', 'project_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = app(MetricsDigestDataSource::class)->project($this->tenant->id, $this->project->id, '2026-11-01', '2026-11-14', 'SAR');
        $withheld = array_values(array_filter($data['fx'], static fn (array $r): bool => $r['withheld'] === true));
        $this->assertNotSame([], $withheld, 'the excluded part is reported, so the pre-send check holds the message for review');
        $this->assertStringContainsString('XEU', (string) $withheld[0]['from']);
    }

    public function test_the_daily_detector_raises_continuity_planning_and_month_events_for_subscribed_projects_only(): void
    {
        $this->subscribe('finance@client.test', 'finance', [$this->project->id], ['event_keys' => json_encode(['digest.month_end', 'digest.month_start', 'campaign.launched'])]);
        $unsubscribed = $this->project($this->tenant, 'Nobody');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'New', 'objective' => 'sales', 'status' => 'active', 'starts_on' => '2026-11-30']);
        UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $unsubscribed->id, 'name' => 'Other', 'objective' => 'sales', 'status' => 'active', 'starts_on' => '2026-11-30']);
        app(TenantContext::class)->forget();

        Carbon::setTestNow(Carbon::parse('2026-12-01 04:30:00', 'UTC'));
        $this->artisan('notifications:v2-external-events')->assertSuccessful();
        $events = DB::table('notification_outbox')->where('scope', 'external')->pluck('event_key')->sort()->values()->all();
        $this->assertSame(['campaign.launched', 'digest.month_end', 'digest.month_start'], $events);
        $this->assertSame(0, DB::table('notification_outbox')->where('project_id', $unsubscribed->id)->count());
        $period = json_decode((string) DB::table('notification_outbox')->where('event_key', 'digest.month_end')->value('payload'), true)['period'];
        $this->assertEquals(['key' => 'last_month', 'from' => '2026-11-01', 'to' => '2026-11-30'], $period);
    }
}
