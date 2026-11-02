<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Models\SpendReconciliationRecord;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SPEND-RECONCILE-RECORD-001 — «آخر مطابقة مؤكدة مع المصدر» is a recorded fact, separate from the sync clock.
 *
 * `integrations:spend-reconcile` stays read-only by default; `--record` writes exactly one totals-only row;
 * the live link shows the latest row for ITS project and still-bound accounts only, or «لم تُطابق بعد».
 */
final class SpendReconcileRecordTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-07-01';

    private const TO = '2026-07-10';

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 11:32:00', 'UTC'));

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'rec-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $this->workspace->id, 'name' => 'P1', 'status' => 'active']);
        $this->other = Project::create(['client_workspace_id' => $this->workspace->id, 'name' => 'P2', 'status' => 'active']);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── the command ─────────────────────────────────────────────────────────────────────────────

    public function test_record_writes_exactly_one_totals_only_row(): void
    {
        $account = $this->account('sandbox', $this->project);
        // The sandbox connector answers 100 on the first day and 220.5 on the last: store 100 + 271.5 → +15.9 %.
        $this->storeSpend($account, 'sbx-cmp-1', self::FROM, 100.0);
        $this->storeSpend($account, 'sbx-cmp-2', self::TO, 271.5);

        $this->artisan('integrations:spend-reconcile', ['account' => $account->getKey(), '--from' => self::FROM, '--to' => self::TO, '--record' => true, '--run-ref' => 'ci-4711'])
            ->expectsOutputToContain('recorded as')
            ->assertSuccessful();

        $rows = SpendReconciliationRecord::withoutGlobalScopes()->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame((string) $this->tenant->id, (string) $row->tenant_id);
        $this->assertSame((string) $this->project->id, (string) $row->project_id, 'the row belongs to the account’s ACTIVE binding');
        $this->assertSame((string) $account->getKey(), (string) $row->external_account_id);
        $this->assertSame('sandbox', $row->provider);
        $this->assertSame(320.5, (float) $row->provider_total);
        $this->assertSame(371.5, (float) $row->stored_total);
        $this->assertSame(51.0, (float) $row->diff);
        $this->assertSame(15.91, (float) $row->diff_pct);
        $this->assertSame(SpendReconciliationRecord::DRIFTED, $row->status);
        $this->assertSame('SAR', $row->currency);
        $this->assertSame('ci-4711', $row->run_ref);
        $this->assertSame('2026-11-02T11:32:00Z', $row->checked_at->toIso8601ZuluString());
        // Totals only: nothing on the row names an account, a campaign or a token.
        $this->assertEqualsCanonicalizing(
            ['id', 'tenant_id', 'project_id', 'external_account_id', 'provider', 'window_from', 'window_to', 'provider_total', 'stored_total', 'currency', 'diff', 'diff_pct', 'status', 'checked_at', 'run_ref'],
            array_keys($row->getAttributes()),
        );
    }

    public function test_a_matching_total_is_recorded_as_matched(): void
    {
        $account = $this->account('sandbox', $this->project);
        $this->storeSpend($account, 'sbx-cmp-1', self::FROM, 100.0);
        $this->storeSpend($account, 'sbx-cmp-2', self::TO, 220.5);

        $this->artisan('integrations:spend-reconcile', ['account' => $account->getKey(), '--from' => self::FROM, '--to' => self::TO, '--record' => true])->assertSuccessful();

        $row = SpendReconciliationRecord::withoutGlobalScopes()->sole();
        $this->assertSame(SpendReconciliationRecord::MATCHED, $row->status);
        $this->assertSame(0.0, (float) $row->diff);
    }

    public function test_without_the_flag_the_command_writes_nothing(): void
    {
        $account = $this->account('sandbox', $this->project);
        $this->storeSpend($account, 'sbx-cmp-1', self::FROM, 100.0);

        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->artisan('integrations:spend-reconcile', ['account' => $account->getKey(), '--from' => self::FROM, '--to' => self::TO])
            ->expectsOutputToContain('read-only; nothing is stored')
            ->assertSuccessful();

        $this->assertSame([], $writes, 'the default run must not INSERT, UPDATE or DELETE anything');
        $this->assertSame(0, SpendReconciliationRecord::withoutGlobalScopes()->count());
    }

    // ── the live link ───────────────────────────────────────────────────────────────────────────

    public function test_the_live_link_carries_the_latest_reconciliation_beside_the_freshness_entry(): void
    {
        $account = $this->account('snapchat', $this->project);
        $this->reconciliation($this->project, $account, 'snapchat', SpendReconciliationRecord::DRIFTED, 15.9, minutesAgo: 120);
        $this->reconciliation($this->project, $account, 'snapchat', SpendReconciliationRecord::MATCHED, 0.0, minutesAgo: 10);

        $entry = $this->entry($this->payload(['snapchat']), 'snapchat');

        $this->assertSame('matched', $entry['reconcile_status'], 'the LATEST row wins');
        $this->assertSame('2026-11-02T11:22:00Z', $entry['last_reconciled_at']);
        $this->assertSame(0.0, (float) $entry['reconcile_diff_pct']);
        $this->assertArrayHasKey('state', $entry, 'the sync facts stay beside it, untouched');
    }

    public function test_a_drift_travels_with_its_percentage_and_an_unchecked_platform_says_never(): void
    {
        $snap = $this->account('snapchat', $this->project);
        $this->account('meta', $this->project);
        $this->reconciliation($this->project, $snap, 'snapchat', SpendReconciliationRecord::DRIFTED, 15.9, minutesAgo: 60);

        $payload = $this->payload(['snapchat', 'meta']);

        $this->assertSame('drifted', $this->entry($payload, 'snapchat')['reconcile_status']);
        $this->assertSame(15.9, (float) $this->entry($payload, 'snapchat')['reconcile_diff_pct']);
        $this->assertSame('never', $this->entry($payload, 'meta')['reconcile_status']);
        $this->assertNull($this->entry($payload, 'meta')['last_reconciled_at']);
    }

    public function test_another_projects_reconciliation_never_appears_on_this_projects_link(): void
    {
        $mine = $this->account('snapchat', $this->project);
        $theirs = $this->account('snapchat', $this->other, externalId: 'theirs');
        $this->reconciliation($this->other, $theirs, 'snapchat', SpendReconciliationRecord::MATCHED, 0.0, minutesAgo: 1);
        // A row FILED under this project for an account the operator has deselected is not this project's either.
        $deselected = $this->account('snapchat', $this->project, externalId: 'desel', active: false);
        $this->reconciliation($this->project, $deselected, 'snapchat', SpendReconciliationRecord::MATCHED, 0.0, minutesAgo: 2);
        $this->assertNotNull($mine);

        $entry = $this->entry($this->payload(['snapchat']), 'snapchat');

        $this->assertSame('never', $entry['reconcile_status']);
        $this->assertNull($entry['last_reconciled_at']);
    }

    public function test_a_link_narrowed_to_one_account_reads_only_that_accounts_check(): void
    {
        $a = $this->account('snapchat', $this->project, externalId: 'a');
        $b = $this->account('snapchat', $this->project, externalId: 'b');
        $this->reconciliation($this->project, $b, 'snapchat', SpendReconciliationRecord::MATCHED, 0.0, minutesAgo: 1);

        $this->assertSame('never', $this->entry($this->payload(['snapchat'], [(string) $a->getKey()]), 'snapchat')['reconcile_status']);
        $this->assertSame('matched', $this->entry($this->payload(['snapchat'], [(string) $b->getKey()]), 'snapchat')['reconcile_status']);
    }

    // ── world ───────────────────────────────────────────────────────────────────────────────────

    private function account(string $provider, Project $bindTo, string $externalId = 'ad', bool $active = true): ExternalAccount
    {
        $this->holdingTenant((string) $this->tenant->id);
        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider.'-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => "{$provider}-{$externalId}", 'name' => 'Account '.$externalId, 'currency' => 'SAR', 'status' => 'active',
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $bindTo->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'advertising', 'is_active' => $active,
        ]);
        app(TenantContext::class)->forget();

        return $account;
    }

    private function storeSpend(ExternalAccount $account, string $campaignExternalId, string $date, float $spend): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'C '.$campaignExternalId, 'status' => 'active']);
        $external = ExternalCampaign::withoutGlobalScopes()->firstOrCreate(
            ['project_id' => $this->project->id, 'provider' => $account->provider, 'external_id' => $campaignExternalId],
            ['tenant_id' => $this->tenant->id, 'external_account_id' => $account->getKey(), 'unified_campaign_id' => $campaign->id, 'name' => $campaignExternalId, 'status' => 'active'],
        );
        app(UpsertDailyMetrics::class)->handle([new NormalizedMetric(
            tenantId: (string) $this->tenant->id, projectId: (string) $this->project->id, provider: $account->provider,
            externalAccountId: (string) $account->getKey(), externalCampaignId: (string) $external->getKey(), unifiedCampaignId: (string) $campaign->id,
            metricDate: Carbon::parse($date), metricKey: 'spend', value: $spend,
            originalCurrency: 'SAR', projectCurrency: 'SAR', exchangeRate: 1.0, originalAmount: $spend,
            originalTimezone: 'UTC', projectTimezone: 'Asia/Riyadh', attributionWindow: 'default', sourceType: 'api',
        )]);
        app(TenantContext::class)->forget();
    }

    private function reconciliation(Project $project, ExternalAccount $account, string $provider, string $status, float $diffPct, int $minutesAgo): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        SpendReconciliationRecord::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->getKey(), 'provider' => $provider,
            'window_from' => self::FROM, 'window_to' => self::TO, 'provider_total' => 1000, 'stored_total' => 1000 * (1 + $diffPct / 100),
            'currency' => 'SAR', 'diff' => 1000 * $diffPct / 100, 'diff_pct' => $diffPct, 'status' => $status,
            'checked_at' => Carbon::now()->subMinutes($minutesAgo),
        ]);
        app(TenantContext::class)->forget();
    }

    /** @param list<string> $providers @param list<string> $accounts */
    private function payload(array $providers, array $accounts = []): array
    {
        $this->holdingTenant((string) $this->tenant->id);
        $campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Camp '.uniqid(), 'status' => 'active']);
        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => self::FROM, 'period_end' => self::TO, 'data' => ['kpis' => ['spend' => 1]],
        ]);
        $scope = ['project_id' => $this->project->id, 'campaign_ids' => [$campaign->id], 'providers' => $providers, 'earliest' => self::FROM, 'latest' => self::TO];
        if ($accounts !== []) {
            $scope['account_ids'] = $accounts;
        }
        [, $raw] = app(ShareService::class)->create($report, ['scope' => $scope], null);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    private function entry(array $payload, string $provider): array
    {
        $entry = collect($payload['freshness'])->firstWhere('provider', $provider);
        $this->assertNotNull($entry, "no freshness entry for {$provider}");

        return $entry;
    }
}
