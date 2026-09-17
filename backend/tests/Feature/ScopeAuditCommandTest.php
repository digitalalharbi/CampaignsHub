<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * ACCOUNT-SCOPE-ISOLATION-001 — the inventory attributes every row to an account and classifies it.
 *
 * The fixture is the case the Owner reported: ONE connection, TWO ad accounts, ONE selected. Rows of
 * every grain exist for both accounts inside the selected account's project, plus an account bound
 * to a different project and an account whose binding was deselected. The command must name each of
 * those situations by its own word, count them per grain, write nothing, and print nothing a reader
 * outside the ad account may see.
 */
final class ScopeAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    private Project $otherProject;

    private ProviderConnection $connection;

    private ExternalAccount $selected;

    private ExternalAccount $unselected;

    private ExternalAccount $elsewhere;

    private ExternalAccount $deselected;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00', 'UTC'));

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = $this->project('Selected project');
        $this->otherProject = $this->project('Other project');

        $this->connection = $this->connection('snapchat');

        // One authorisation, several accounts — only one of them chosen for this project.
        $this->selected = $this->account('act-selected', 'SECRET-NAME-SELECTED');
        $this->unselected = $this->account('act-unselected', 'SECRET-NAME-UNSELECTED');
        $this->elsewhere = $this->account('act-elsewhere', 'SECRET-NAME-ELSEWHERE');
        $this->deselected = $this->account('act-deselected', 'SECRET-NAME-DESELECTED');

        $this->bind($this->selected, $this->project, true);
        $this->bind($this->elsewhere, $this->otherProject, true);
        $this->bind($this->deselected, $this->project, false);

        foreach ([$this->selected, $this->unselected, $this->elsewhere, $this->deselected] as $account) {
            $this->seedEveryGrain($account, $this->project);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_grain_is_attributed_and_classified_against_the_bindings(): void
    {
        $output = $this->audit();

        foreach ([
            'external_campaigns', 'external_ad_sets', 'external_ads', 'external_creatives',
            'daily_metrics', 'entity_daily_metrics', 'creative_daily_metrics', 'metric_sync_runs', 'commerce_orders',
        ] as $grain) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($grain, '/').' — \d+ row\(s\); \d+ outside the ACTIVE bound set/',
                $output,
                "{$grain} was not reported",
            );
        }

        // The four situations, each named by its own word against the account that is in it.
        $this->assertMatchesRegularExpression('/bound\s+'.preg_quote($this->selected->id, '/').'/', $output);
        $this->assertMatchesRegularExpression('/unbound\s+'.preg_quote($this->unselected->id, '/').'/', $output);
        $this->assertMatchesRegularExpression('/bound_elsewhere\s+'.preg_quote($this->elsewhere->id, '/').'/', $output);
        $this->assertMatchesRegularExpression('/once_bound\s+'.preg_quote($this->deselected->id, '/').'/', $output);

        // The selected account is never reported as anything but bound.
        $this->assertDoesNotMatchRegularExpression('/(unbound|once_bound|bound_elsewhere)\s+'.preg_quote($this->selected->id, '/').'/', $output);

        // The dated grains carry the month a cleanup would be bounded by.
        $this->assertMatchesRegularExpression('/daily_metrics[\s\S]*?\[snapchat\] 2026-08\s+\d+/', $output);
        $this->assertMatchesRegularExpression('/daily_metrics[\s\S]*?\[snapchat\] 2026-09\s+\d+/', $output);
    }

    public function test_the_inventory_counts_only_the_rows_outside_the_active_set(): void
    {
        $output = $this->audit();

        // Four accounts × one campaign each: three of the four are outside the active set.
        $this->assertMatchesRegularExpression(
            '/'.preg_quote($this->project->id, '/').'\s+external_campaigns\s+4\s+3\s+1\s+1\s+1\s+0/',
            $output,
            'the campaigns line of the inventory does not read total 4 · outside 3 · once 1 · elsewhere 1 · unbound 1 · none 0',
        );

        // Two metric days per account: eight rows, six outside.
        $this->assertMatchesRegularExpression(
            '/'.preg_quote($this->project->id, '/').'\s+daily_metrics\s+8\s+6\s+2\s+2\s+2\s+0/',
            $output,
        );
    }

    public function test_a_month_window_narrows_the_dated_grains_only(): void
    {
        $output = $this->audit(['--from' => '2026-09', '--to' => '2026-09']);

        $this->assertMatchesRegularExpression('/daily_metrics\s+4\s+3\s+/', $output, 'the September window should keep one day per account');
        $this->assertDoesNotMatchRegularExpression('/daily_metrics[\s\S]*?\[snapchat\] 2026-08/', $output);
        // Structure is undated and untouched by the window.
        $this->assertMatchesRegularExpression('/external_campaigns\s+4\s+3\s+/', $output);
    }

    public function test_the_estate_names_the_cross_project_and_cross_tenant_mechanisms(): void
    {
        // The same account ACTIVE on two projects at once.
        $this->bind($this->selected, $this->otherProject, true);

        // The same provider account discovered under a second tenant.
        $foreign = Tenant::create(['name' => 'F', 'slug' => 'f-'.uniqid(), 'status' => 'active']);
        $foreignConnection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $foreign->id, 'credential_id' => $this->connection->credential_id,
            'provider' => 'snapchat', 'connection_name' => 'f', 'scope' => 'project_only', 'status' => 'connected',
        ]);
        ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $foreign->id, 'provider_connection_id' => $foreignConnection->id, 'provider' => 'snapchat',
            'account_type' => 'ad_account', 'external_id' => 'act-selected', 'name' => 'x', 'status' => 'active',
        ]);

        $output = $this->audit();

        $this->assertStringContainsString('accounts with an ACTIVE binding to more than one project : 1', $output);
        $this->assertStringContainsString('provider accounts discovered under more than one tenant    : 1', $output);
    }

    public function test_it_writes_nothing_and_prints_no_name(): void
    {
        $before = $this->digestOfEveryTable();

        $output = $this->audit();

        $this->assertSame($before, $this->digestOfEveryTable(), 'the audit changed a table it was only meant to read');

        $this->assertStringNotContainsString('SECRET-NAME', $output, 'an account name reached the output');
        $this->assertStringNotContainsString('act-selected', $output, 'a provider account id reached the output');
        $this->assertStringNotContainsString('https://', $output, 'a url reached the output');
    }

    // ── fixture ────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string,string> $options */
    private function audit(array $options = []): string
    {
        Artisan::call('integrations:scope-audit', ['--project' => $this->project->id] + $options);

        return Artisan::output();
    }

    private function project(string $name): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function connection(string $provider): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider.'-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
    }

    private function account(string $externalId, string $name): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->connection->id, 'provider' => 'snapchat',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $name, 'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }

    /** One row of every grain for this account, filed in the given project. */
    private function seedEveryGrain(ExternalAccount $account, Project $project): void
    {
        $base = ['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()];
        $campaign = (string) Str::uuid();
        $adSet = (string) Str::uuid();
        $ad = (string) Str::uuid();
        $creative = (string) Str::uuid();

        DB::table('external_campaigns')->insert($base + [
            'id' => $campaign, 'external_account_id' => $account->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-'.$account->external_id, 'name' => 'n', 'status' => 'active',
        ]);
        DB::table('external_ad_sets')->insert($base + [
            'id' => $adSet, 'external_campaign_id' => $campaign, 'provider' => 'snapchat', 'external_id' => 'sq-'.$account->external_id, 'name' => 'n',
        ]);
        DB::table('external_creatives')->insert($base + [
            'id' => $creative, 'external_campaign_id' => $campaign, 'provider' => 'snapchat',
            'external_creative_id' => 'cr-'.$account->external_id, 'name' => 'n', 'asset_url' => 'https://cdn.example/'.$account->external_id,
        ]);
        DB::table('external_ads')->insert($base + [
            'id' => $ad, 'external_campaign_id' => $campaign, 'external_ad_set_id' => $adSet, 'creative_id' => $creative,
            'provider' => 'snapchat', 'external_id' => 'ad-'.$account->external_id, 'name' => 'n',
        ]);

        foreach (['2026-08-30', '2026-09-02'] as $date) {
            DB::table('daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                'provider' => 'snapchat', 'metric_key' => 'spend', 'metric_date' => $date, 'value' => 10,
            ]);
            DB::table('entity_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                'provider' => 'snapchat', 'entity_type' => 'ad', 'entity_id' => $ad, 'external_entity_id' => 'ad-'.$account->external_id,
                'metric_date' => $date, 'spend' => 1,
            ]);
            DB::table('creative_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'creative_id' => $creative, 'metric_date' => $date, 'spend' => 1,
            ]);
            DB::table('commerce_orders')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'provider' => 'salla',
                'external_id' => 'o-'.$account->external_id.'-'.$date, 'placed_at' => $date.' 10:00:00', 'total' => 5,
            ]);
        }

        DB::table('metric_sync_runs')->insert($base + [
            'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'provider' => 'snapchat', 'status' => 'success',
            'window_start' => '2026-09-01', 'window_end' => '2026-09-07',
        ]);
        DB::table('integration_raw_payloads')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'external_account_id' => $account->id,
            'provider' => 'snapchat', 'resource' => 'insights', 'payload' => json_encode(['url' => 'https://secret.example/'.$account->external_id]),
            'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function digestOfEveryTable(): array
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->where('table_name', '!=', 'migrations')
            ->orderBy('table_name')
            ->pluck('table_name');

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
