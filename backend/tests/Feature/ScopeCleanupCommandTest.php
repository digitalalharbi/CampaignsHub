<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — the cleanup touches ONLY rows outside the selected set, and only when told.
 */
final class ScopeCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    private Project $otherProject;

    private ProviderConnection $connection;

    private ExternalAccount $selected;

    private ExternalAccount $foreign;

    private ExternalAccount $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00', 'UTC'));

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = $this->project('Selected');
        $this->otherProject = $this->project('Other');
        $this->connection = $this->connection();

        $this->selected = $this->account('act-selected');
        $this->foreign = $this->account('act-foreign');
        $this->elsewhere = $this->account('act-elsewhere');

        $this->bind($this->selected, $this->project, true);
        $this->bind($this->elsewhere, $this->otherProject, true);

        foreach ([$this->selected, $this->foreign, $this->elsewhere] as $account) {
            $this->seedEveryGrain($account, $this->project);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_dry_run_prints_the_plan_and_writes_nothing(): void
    {
        $before = $this->digestOfEveryTable();

        $code = Artisan::call('integrations:scope-cleanup', ['--project' => $this->project->id, '--account' => $this->foreign->id]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('dry run, nothing will change', $output);
        $this->assertMatchesRegularExpression('/daily_metrics\s+2026-08\s+3/', $output);
        $this->assertMatchesRegularExpression('/daily_metrics\s+2026-09\s+3/', $output);
        $this->assertMatchesRegularExpression('/external_campaigns\s+—\s+1/', $output);
        $this->assertSame($before, $this->digestOfEveryTable(), 'the dry run changed a table');
    }

    public function test_it_refuses_to_touch_an_account_that_is_selected_for_the_project(): void
    {
        $before = $this->digestOfEveryTable();

        $code = Artisan::call('integrations:scope-cleanup', ['--project' => $this->project->id, '--account' => $this->selected->id, '--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', Artisan::output());
        $this->assertSame($before, $this->digestOfEveryTable());
    }

    public function test_remove_deletes_only_the_foreign_accounts_rows_and_is_idempotent(): void
    {
        $selectedBefore = $this->digestOfAccountRows($this->selected);
        $elsewhereBefore = $this->digestOfAccountRows($this->elsewhere);

        $code = Artisan::call('integrations:scope-cleanup', ['--project' => $this->project->id, '--account' => $this->foreign->id, '--apply' => true]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame(0, $this->rowsOf($this->foreign), 'the foreign account still has rows in the project');
        $this->assertSame($selectedBefore, $this->digestOfAccountRows($this->selected), 'a selected account\'s rows were touched');
        $this->assertSame($elsewhereBefore, $this->digestOfAccountRows($this->elsewhere), 'another account\'s rows were touched');

        Artisan::call('integrations:scope-cleanup', ['--project' => $this->project->id, '--account' => $this->foreign->id, '--apply' => true]);
        $this->assertStringContainsString('Nothing to do', Artisan::output());

        $this->assertDatabaseHas('audit_logs', ['action' => 'integration.scope_cleanup.remove', 'entity_id' => $this->foreign->id]);
    }

    public function test_remove_refuses_an_account_that_is_selected_elsewhere(): void
    {
        $code = Artisan::call('integrations:scope-cleanup', ['--project' => $this->project->id, '--account' => $this->elsewhere->id, '--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('reassign', Artisan::output());
        $this->assertGreaterThan(0, $this->rowsOf($this->elsewhere));
    }

    public function test_reassign_moves_the_rows_to_the_accounts_own_project_and_adopts_them_there(): void
    {
        $code = Artisan::call('integrations:scope-cleanup', ['--project' => $this->project->id, '--account' => $this->elsewhere->id, '--action' => 'reassign', '--apply' => true]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame(0, $this->rowsOf($this->elsewhere, $this->project->id), 'rows stayed in the project the account left');

        $campaign = DB::table('external_campaigns')->where('external_account_id', $this->elsewhere->id)->first();
        $this->assertSame($this->otherProject->id, $campaign->project_id);
        $unified = UnifiedCampaign::withoutGlobalScopes()->findOrFail($campaign->unified_campaign_id);
        $this->assertSame($this->otherProject->id, $unified->project_id);

        foreach (['external_ad_sets', 'external_ads', 'external_creatives'] as $table) {
            $this->assertSame(1, DB::table($table)->where('external_campaign_id', $campaign->id)->where('project_id', $this->otherProject->id)->count(), "{$table} did not follow");
        }
        $this->assertSame(6, DB::table('daily_metrics')->where('external_account_id', $this->elsewhere->id)->where('project_id', $this->otherProject->id)->where('unified_campaign_id', $unified->id)->count());
        $this->assertSame(2, DB::table('creative_daily_metrics')->where('campaign_id', $unified->id)->where('project_id', $this->otherProject->id)->count());

        // The selected account was not touched.
        $this->assertSame(1, DB::table('external_campaigns')->where('external_account_id', $this->selected->id)->where('project_id', $this->project->id)->count());
    }

    public function test_reassign_refuses_an_account_with_no_provenance(): void
    {
        $code = Artisan::call('integrations:scope-cleanup', ['--project' => $this->project->id, '--account' => $this->foreign->id, '--action' => 'reassign', '--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('provenance', Artisan::output());
        $this->assertGreaterThan(0, $this->rowsOf($this->foreign));
    }

    public function test_a_month_window_bounds_the_dated_grains_and_leaves_structure_alone(): void
    {
        Artisan::call('integrations:scope-cleanup', [
            '--project' => $this->project->id, '--account' => $this->foreign->id, '--grain' => 'figures',
            '--from' => '2026-08', '--to' => '2026-08', '--apply' => true,
        ]);

        $this->assertSame(3, DB::table('daily_metrics')->where('external_account_id', $this->foreign->id)->count(), 'September rows should survive an August window');
        $this->assertSame(1, DB::table('external_campaigns')->where('external_account_id', $this->foreign->id)->count(), 'structure is not a figure grain');
    }

    // ── fixture ────────────────────────────────────────────────────────────────────────────────────

    private function rowsOf(ExternalAccount $account, ?string $project = null): int
    {
        $project ??= $this->project->id;
        $campaigns = DB::table('external_campaigns')->where('external_account_id', $account->id)->pluck('id');

        return DB::table('external_campaigns')->where('external_account_id', $account->id)->where('project_id', $project)->count()
            + DB::table('daily_metrics')->where('external_account_id', $account->id)->where('project_id', $project)->count()
            + DB::table('entity_daily_metrics')->where('external_account_id', $account->id)->where('project_id', $project)->count()
            + DB::table('external_ad_sets')->whereIn('external_campaign_id', $campaigns)->where('project_id', $project)->count()
            + DB::table('external_ads')->whereIn('external_campaign_id', $campaigns)->where('project_id', $project)->count()
            + DB::table('external_creatives')->whereIn('external_campaign_id', $campaigns)->where('project_id', $project)->count()
            + DB::table('creative_daily_metrics')->whereIn('creative_id', DB::table('external_creatives')->select('id')->whereIn('external_campaign_id', $campaigns))->where('project_id', $project)->count()
            + DB::table('metric_sync_runs')->where('external_account_id', $account->id)->where('project_id', $project)->count()
            + DB::table('commerce_orders')->where('external_account_id', $account->id)->where('project_id', $project)->count();
    }

    private function digestOfAccountRows(ExternalAccount $account): string
    {
        $campaigns = DB::table('external_campaigns')->where('external_account_id', $account->id)->pluck('id')->all();
        $out = '';
        foreach ([
            'external_campaigns' => ['external_account_id', [$account->id]],
            'daily_metrics' => ['external_account_id', [$account->id]],
            'entity_daily_metrics' => ['external_account_id', [$account->id]],
            'external_ad_sets' => ['external_campaign_id', $campaigns],
            'external_ads' => ['external_campaign_id', $campaigns],
            'external_creatives' => ['external_campaign_id', $campaigns],
            'commerce_orders' => ['external_account_id', [$account->id]],
        ] as $table => [$column, $ids]) {
            $row = DB::table($table)->whereIn($column, $ids)
                ->selectRaw("COUNT(*) AS n, MD5(COALESCE(string_agg({$table}::text, '' ORDER BY {$table}::text), '')) AS d")
                ->first();
            $out .= "{$table}:{$row->n}:{$row->d};";
        }

        return $out;
    }

    private function project(string $name): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function connection(): ProviderConnection
    {
        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => 'snapchat', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snap-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
    }

    private function account(string $externalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->connection->id, 'provider' => 'snapchat',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }

    /** One campaign, one ad set, one ad, one creative, three metric keys on two days across two months, a run, two orders. */
    private function seedEveryGrain(ExternalAccount $account, Project $project): void
    {
        $base = ['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()];
        $campaign = (string) Str::uuid();
        $adSet = (string) Str::uuid();
        $ad = (string) Str::uuid();
        $creative = (string) Str::uuid();

        DB::table('external_campaigns')->insert($base + [
            'id' => $campaign, 'external_account_id' => $account->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-'.$account->external_id, 'name' => 'Launch '.$account->external_id, 'status' => 'active', 'objective' => 'WEB_CONVERSION',
        ]);
        DB::table('external_ad_sets')->insert($base + ['id' => $adSet, 'external_campaign_id' => $campaign, 'provider' => 'snapchat', 'external_id' => 'sq-'.$account->external_id, 'name' => 'n']);
        DB::table('external_creatives')->insert($base + ['id' => $creative, 'external_campaign_id' => $campaign, 'provider' => 'snapchat', 'external_creative_id' => 'cr-'.$account->external_id, 'name' => 'n']);
        DB::table('external_ads')->insert($base + ['id' => $ad, 'external_campaign_id' => $campaign, 'external_ad_set_id' => $adSet, 'creative_id' => $creative, 'provider' => 'snapchat', 'external_id' => 'ad-'.$account->external_id, 'name' => 'n']);

        foreach (['2026-08-30', '2026-09-02'] as $date) {
            foreach (['spend', 'impressions', 'clicks'] as $key) {
                DB::table('daily_metrics')->insert($base + [
                    'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                    'provider' => 'snapchat', 'metric_key' => $key, 'metric_date' => $date, 'value' => 10,
                ]);
            }
            DB::table('entity_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                'provider' => 'snapchat', 'entity_type' => 'ad', 'entity_id' => $ad, 'external_entity_id' => 'ad-'.$account->external_id,
                'metric_date' => $date, 'spend' => 1,
            ]);
            DB::table('creative_daily_metrics')->insert($base + ['id' => (string) Str::uuid(), 'creative_id' => $creative, 'metric_date' => $date, 'spend' => 1]);
            DB::table('commerce_orders')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'provider' => 'salla',
                'external_id' => 'o-'.$account->external_id.'-'.$date, 'placed_at' => $date.' 10:00:00', 'total' => 5,
            ]);
        }

        DB::table('metric_sync_runs')->insert($base + [
            'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'provider' => 'snapchat', 'status' => 'success',
            'window_start' => '2026-09-01', 'window_end' => '2026-09-07',
        ]);
    }

    /** @return array<string, string> */
    private function digestOfEveryTable(): array
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')->where('table_type', 'BASE TABLE')->where('table_name', '!=', 'migrations')
            ->orderBy('table_name')->pluck('table_name');

        $digests = [];
        foreach ($tables as $table) {
            $row = DB::table($table)
                ->selectRaw("COUNT(*) AS rows_found, MD5(COALESCE(string_agg(t::text, '' ORDER BY t::text), '')) AS digest")
                ->fromRaw("\"{$table}\" AS t")
                ->first();
            $digests[$table] = ((int) ($row->rows_found ?? 0)).':'.((string) ($row->digest ?? ''));
        }

        return $digests;
    }
}
