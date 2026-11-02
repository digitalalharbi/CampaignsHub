<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-002 — the audit that reads the CONTENT of an account, and the quarantine
 * that takes a foreign copy out of a project without losing it.
 *
 * The fixture is the incident: one ChatGPT Ads connection whose key answers for account B, two
 * account rows on it (A and B) bound to two projects, and B's campaign `cmp_shared` filed under BOTH
 * accounts with figures in both projects. A's copy is the leak.
 */
final class AccountOwnershipAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $projectA;

    private Project $projectB;

    private ExternalAccount $accountA;

    private ExternalAccount $accountB;

    private string $campaignA;

    private string $campaignB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'UTC'));

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->projectA = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'A', 'status' => 'active',
        ]);
        $this->projectB = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'B', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'openai_ads',
            tokens: new OAuthTokens('sk-ads-live-belongs-to-b-0000', null, null),
            connectionName: 'ChatGPT Ads', credentialType: 'api_key',
        );

        $this->accountA = $this->account($connection, 'acct_a', 'SECRET-NAME-A');
        $this->accountB = $this->account($connection, 'acct_b', 'SECRET-NAME-B');

        foreach ([[$this->accountA, $this->projectA, $workspace], [$this->accountB, $this->projectB, $workspace]] as [$account, $project, $ws]) {
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'project_id' => $project->id,
                'external_account_id' => $account->id, 'provider' => 'openai_ads', 'purpose' => 'advertising', 'is_active' => true,
            ]);
        }

        // The same provider campaign under both accounts: B's own copy, and the copy that leaked into A.
        $this->campaignA = $this->seedCampaign($this->accountA, $this->projectA, 'cmp_shared');
        $this->campaignB = $this->seedCampaign($this->accountB, $this->projectB, 'cmp_shared');

        // The key answers for B, and B's account lists the shared campaign.
        $this->providerLists(['cmp_shared']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_audit_names_the_campaign_filed_under_two_accounts_and_writes_nothing(): void
    {
        $before = $this->digestOfEveryTable();

        $output = $this->audit();

        $this->assertStringContainsString('provider campaign ids under two or more accounts : 1', $output);
        $this->assertStringContainsString('account '.$this->accountA->id.'  project '.$this->projectA->id, $output);
        $this->assertStringContainsString('account '.$this->accountB->id.'  project '.$this->projectB->id, $output);
        $this->assertStringNotContainsString('SECRET-NAME', $output);
        $this->assertStringNotContainsString('sk-ads-live', $output);

        $this->assertSame($before, $this->digestOfEveryTable(), 'the audit changed a table it was only meant to read');
    }

    public function test_a_future_dated_figure_is_reported_by_account(): void
    {
        DB::table('daily_metrics')->insert($this->metricRow($this->accountB, $this->projectB, $this->campaignB, '2027-11-01'));

        $output = $this->audit();

        $this->assertMatchesRegularExpression('/daily_metrics: 1 row\(s\)\s+· \[openai_ads\] account '.preg_quote($this->accountB->id, '/').'.*latest 2027-11-01/', $output);
    }

    public function test_the_probe_classifies_the_rows_on_a_connection_by_what_its_key_answers_for(): void
    {
        $before = $this->digestOfEveryTable();

        $output = $this->audit(['--probe' => true]);

        $this->assertMatchesRegularExpression('/OWNED\s+account '.preg_quote($this->accountB->id, '/').'/', $output);
        $this->assertMatchesRegularExpression('/FOREIGN\s+account '.preg_quote($this->accountA->id, '/').'/', $output);
        $this->assertStringContainsString('foreign account rows: 1', $output);

        $this->assertSame($before, $this->digestOfEveryTable(), 'a probe changed a table');
    }

    /**
     * The current key cannot name the owner: it answers for B here, and had the connection just been
     * re-keyed for A this same evidence would point the other way. So no owner, no quarantine.
     */
    public function test_apply_without_a_named_owner_is_refused(): void
    {
        $before = $this->digestOfEveryTable();

        $this->assertSame(1, Artisan::call('integrations:account-ownership-audit', ['--apply' => true, '--probe' => true]));

        $this->assertSame($before, $this->digestOfEveryTable());
    }

    /** The dry run is what the Owner approves: counts and spend per project, and not one byte written. */
    public function test_the_dry_run_prints_the_plan_and_writes_nothing(): void
    {
        $before = $this->digestOfEveryTable();

        $output = $this->audit(['--dry-run' => true, '--keep-account' => $this->accountB->id]);

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('external_campaigns', $output);
        $this->assertMatchesRegularExpression('/project '.preg_quote($this->projectA->id, '/').'\s+account '.preg_quote($this->accountA->id, '/').'\s+campaigns leaving 1\s+spend 375\.46 → 0\.00/', $output);
        $this->assertStringContainsString('the named owner (account '.$this->accountB->id.') is not touched: 1 campaigns', $output);
        $this->assertDoesNotMatchRegularExpression('/batch [0-9a-f-]{36}/', $output);

        $this->assertSame($before, $this->digestOfEveryTable(), 'the dry run changed a table');
        $this->assertSame(0, DB::table('isolation_quarantines')->count());
    }

    public function test_the_dry_run_needs_a_named_owner_too(): void
    {
        $this->assertSame(1, Artisan::call('integrations:account-ownership-audit', ['--dry-run' => true]));
    }

    /** One provider account active in two projects is printed for a person to judge. */
    public function test_a_provider_account_active_in_two_projects_is_reported(): void
    {
        $this->assertStringContainsString('provider accounts active in two or more projects : 0', $this->audit());

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'openai_ads', tokens: new OAuthTokens('sk-ads-live-second-00000', null, null),
            connectionName: 'Second', clientWorkspaceId: $this->projectA->client_workspace_id, credentialType: 'api_key',
        );
        $twin = $this->account($connection, 'acct_b', 'SECRET-NAME-B');
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'external_account_id' => $twin->id, 'provider' => 'openai_ads', 'purpose' => 'advertising', 'is_active' => true,
        ]);

        $this->assertStringContainsString('provider accounts active in two or more projects : 1', $this->audit());
    }

    public function test_apply_quarantines_the_foreign_copy_whole_and_leaves_the_owner_untouched(): void
    {
        $ownerBefore = $this->rowsOf($this->campaignB);

        $output = $this->audit(['--apply' => true, '--keep-account' => $this->accountB->id]);

        // A's project no longer holds the campaign or any figure of it.
        $this->assertSame(0, DB::table('external_campaigns')->where('project_id', $this->projectA->id)->count());
        $this->assertSame(0, DB::table('daily_metrics')->where('project_id', $this->projectA->id)->count());
        $this->assertSame(0, DB::table('entity_daily_metrics')->where('project_id', $this->projectA->id)->count());
        $this->assertSame(0, DB::table('external_ads')->where('project_id', $this->projectA->id)->count());
        $this->assertSame(0, DB::table('external_creatives')->where('project_id', $this->projectA->id)->count());
        $this->assertSame(0, DB::table('creative_daily_metrics')->where('project_id', $this->projectA->id)->count());

        // B's rows are exactly what they were.
        $this->assertSame($ownerBefore, $this->rowsOf($this->campaignB));

        // Nothing was lost: every removed row is in the quarantine, whole, under one batch.
        preg_match('/batch ([0-9a-f-]{36})/', $output, $m);
        $this->assertNotEmpty($m, 'no batch id printed');
        $quarantined = DB::table('isolation_quarantines')->where('batch', $m[1])->get();
        $this->assertSame(['creative_daily_metrics' => 2, 'daily_metrics' => 2, 'entity_daily_metrics' => 2, 'external_ad_sets' => 1,
            'external_ads' => 1, 'external_campaigns' => 1, 'external_creatives' => 1],
            $quarantined->countBy('table_name')->sortKeys()->all());
        $this->assertSame($this->campaignA, $quarantined->firstWhere('table_name', 'external_campaigns')->row_id);

        // And the batch comes back, row for row.
        $this->assertSame(0, Artisan::call('integrations:account-ownership-audit', ['--restore' => $m[1]]));
        $this->assertSame(1, DB::table('external_campaigns')->where('id', $this->campaignA)->count());
        $this->assertSame(2, DB::table('daily_metrics')->where('external_campaign_id', $this->campaignA)->count());
        $this->assertSame(2, DB::table('creative_daily_metrics')->where('project_id', $this->projectA->id)->count());
        $this->assertSame(0, DB::table('isolation_quarantines')->where('batch', $m[1])->whereNull('restored_at')->count());
    }

    /** The provider's word decides: a campaign the owner's account does not list is never quarantined. */
    public function test_a_campaign_the_provider_does_not_list_under_the_owner_is_not_selected(): void
    {
        $this->providerLists([]);

        $output = $this->audit(['--apply' => true, '--keep-account' => $this->accountB->id]);

        $this->assertStringContainsString('NOT LISTED', $output);
        $this->assertSame(1, DB::table('external_campaigns')->where('id', $this->campaignA)->count());
        $this->assertSame(0, DB::table('isolation_quarantines')->count());
    }

    /** A key that answers for another account confirms nothing, so nothing is selected. */
    public function test_a_key_for_another_account_cannot_confirm_the_owner(): void
    {
        // The connection's key answers for acct_b; naming A as the owner must select nothing.
        $output = $this->audit(['--apply' => true, '--keep-account' => $this->accountA->id]);

        $this->assertStringContainsString('cannot confirm', $output);
        $this->assertSame(1, DB::table('external_campaigns')->where('id', $this->campaignB)->count());
        $this->assertSame(0, DB::table('isolation_quarantines')->count());
    }

    public function test_the_dry_run_shows_what_the_provider_confirms(): void
    {
        $output = $this->audit(['--dry-run' => true, '--keep-account' => $this->accountB->id]);

        $this->assertMatchesRegularExpression('/CONFIRMED\s+campaign '.preg_quote($this->campaignA, '/').'/', $output);
        $this->assertStringContainsString('1 of the 1 candidate copies are confirmed', $output);
    }

    /** @var list<string> the campaigns the provider currently lists under the key's account */
    private array $listed = [];

    private bool $faked = false;

    /**
     * Registered once and read per call. `Http::fake()` merges stubs and the FIRST match wins, so a
     * second registration would silently keep answering with the first list.
     *
     * @param  list<string>  $campaigns
     */
    private function providerLists(array $campaigns): void
    {
        $this->listed = $campaigns;

        if ($this->faked) {
            return;
        }

        $this->faked = true;
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/campaigns')) {
                return Http::response(['data' => array_map(static fn (string $id): array => ['id' => $id, 'name' => 'n'], $this->listed), 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_b', 'object' => 'ad_account', 'name' => 'SECRET-NAME-B']);
        });
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────

    private function account(ProviderConnection $connection, string $externalId, string $name): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'openai_ads',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $name, 'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    /** One campaign with an ad set, an ad, a creative and two days of every figure, filed in the given project. */
    private function seedCampaign(ExternalAccount $account, Project $project, string $externalId): string
    {
        $base = ['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()];
        $campaign = (string) Str::uuid();
        $adSet = (string) Str::uuid();
        $ad = (string) Str::uuid();
        $creative = (string) Str::uuid();

        DB::table('external_campaigns')->insert($base + [
            'id' => $campaign, 'external_account_id' => $account->id, 'provider' => 'openai_ads',
            'external_id' => $externalId, 'name' => 'SECRET-NAME-CAMPAIGN', 'status' => 'active',
        ]);
        DB::table('external_ad_sets')->insert($base + [
            'id' => $adSet, 'external_campaign_id' => $campaign, 'provider' => 'openai_ads', 'external_id' => 'grp-'.$account->external_id, 'name' => 'n',
        ]);
        DB::table('external_creatives')->insert($base + [
            'id' => $creative, 'external_campaign_id' => $campaign, 'provider' => 'openai_ads',
            'external_creative_id' => 'cr-'.$account->external_id, 'name' => 'n', 'asset_url' => 'https://cdn.example/'.$account->external_id,
        ]);
        DB::table('external_ads')->insert($base + [
            'id' => $ad, 'external_campaign_id' => $campaign, 'external_ad_set_id' => $adSet, 'creative_id' => $creative,
            'provider' => 'openai_ads', 'external_id' => 'ad-'.$account->external_id, 'name' => 'n',
        ]);

        foreach (['2026-10-25', '2026-10-26'] as $date) {
            DB::table('daily_metrics')->insert($this->metricRow($account, $project, $campaign, $date));
            DB::table('entity_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                'provider' => 'openai_ads', 'entity_type' => 'ad', 'entity_id' => $ad, 'external_entity_id' => 'ad-'.$account->external_id,
                'metric_date' => $date, 'spend' => 1,
            ]);
            DB::table('creative_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'creative_id' => $creative, 'metric_date' => $date, 'spend' => 1,
            ]);
        }

        return $campaign;
    }

    /** @return array<string, mixed> */
    private function metricRow(ExternalAccount $account, Project $project, string $campaign, string $date): array
    {
        return [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'external_campaign_id' => $campaign, 'provider' => 'openai_ads',
            'metric_key' => 'spend', 'metric_date' => $date, 'value' => 187.73, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @return array<string, string> table → digest of the rows hanging off this campaign */
    private function rowsOf(string $campaign): array
    {
        $out = [];

        foreach (['external_campaigns' => 'id', 'external_ad_sets' => 'external_campaign_id', 'external_ads' => 'external_campaign_id',
            'external_creatives' => 'external_campaign_id', 'daily_metrics' => 'external_campaign_id', 'entity_daily_metrics' => 'external_campaign_id'] as $table => $column) {
            $out[$table] = DB::table($table)->where($column, $campaign)->orderBy('id')->get()->map(static fn ($r) => json_encode($r))->implode('|');
        }

        return $out;
    }

    /** @param array<string, mixed> $options */
    private function audit(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('integrations:account-ownership-audit', $options));

        return Artisan::output();
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
