<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Mail\OperationalDigestMail;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Notifications\Support\OperationalDigestModel;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EMAIL-DIGESTS-OPERATIONAL-001 — the digest's numbers ARE the report link's numbers.
 *
 * A project is seeded on two platforms for each objective, the digest is built over the window and a
 * LIVE report link is minted over the same window; the link's totals and its per-platform rows must
 * equal what the digest carries, key by key. The digest has no pipeline of its own — this is the proof.
 */
final class OperationalDigestReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2026-09-30';

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'od-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'Op', 'email' => 'od-'.uniqid().'@a.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    /**
     * @param  array<string, array<string, float>>  $perProvider  provider → metric key → value
     * @return array{project: Project, accounts: list<string>}
     */
    private function seedProject(string $name, string $objective, array $perProvider): array
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C'.$name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => $name, 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $project->getKey());

        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $project->getKey(), 'name' => 'Camp '.$name,
            'status' => 'active', 'objective' => $objective, 'platforms' => array_keys($perProvider),
        ]);

        $accounts = [];
        foreach ($perProvider as $provider => $metrics) {
            $connection = app(TokenVault::class)->open(
                tenantId: (string) $this->tenant->getKey(), provider: $provider,
                tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider.uniqid(),
            );
            $account = ExternalAccount::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
                'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'acc-'.uniqid(),
                'name' => $provider, 'currency' => 'USD', 'status' => 'active',
            ]);
            $accounts[] = (string) $account->getKey();
            $external = ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->getKey(), 'project_id' => $project->getKey(), 'external_account_id' => $account->getKey(),
                'unified_campaign_id' => $campaign->getKey(), 'provider' => $provider, 'external_id' => 'e-'.uniqid(), 'name' => 'Camp', 'status' => 'active',
            ]);
            $rows = [];
            foreach ($metrics as $key => $value) {
                $rows[] = new NormalizedMetric(
                    tenantId: (string) $this->tenant->getKey(), projectId: (string) $project->getKey(), provider: $provider,
                    externalAccountId: (string) $account->getKey(), externalCampaignId: (string) $external->getKey(),
                    unifiedCampaignId: (string) $campaign->getKey(), metricDate: Carbon::parse('2026-09-15'),
                    metricKey: $key, value: $value, projectCurrency: 'USD',
                );
            }
            app(UpsertDailyMetrics::class)->handle($rows);
        }

        return ['project' => $project, 'accounts' => $accounts];
    }

    /** @return array<string, array{0: string, 1: array<string, array<string, float>>, 2: list<string>}> */
    public static function objectives(): array
    {
        return [
            'sales' => ['sales', [
                'meta' => ['spend' => 500.0, 'impressions' => 90000.0, 'clicks' => 1200.0, 'purchases' => 40.0, 'revenue' => 2600.0],
                'snapchat' => ['spend' => 300.0, 'impressions' => 50000.0, 'clicks' => 700.0, 'purchases' => 25.0, 'revenue' => 1500.0],
            ], ['spend', 'purchases', 'revenue', 'roas']],
            'leads' => ['leads', [
                'meta' => ['spend' => 400.0, 'impressions' => 80000.0, 'clicks' => 1000.0, 'leads' => 50.0],
                'tiktok' => ['spend' => 200.0, 'impressions' => 60000.0, 'clicks' => 900.0, 'leads' => 10.0],
            ], ['spend', 'leads', 'cpl']],
            'traffic' => ['traffic', [
                'google' => ['spend' => 250.0, 'impressions' => 70000.0, 'clicks' => 3000.0],
                'meta' => ['spend' => 150.0, 'impressions' => 40000.0, 'clicks' => 1800.0],
            ], ['spend', 'clicks', 'cpc']],
            'awareness' => ['awareness', [
                'tiktok' => ['spend' => 600.0, 'impressions' => 900000.0, 'reach' => 400000.0],
                'snapchat' => ['spend' => 200.0, 'impressions' => 300000.0, 'reach' => 150000.0],
            ], ['spend', 'impressions', 'cpm']],
            'app_installs' => ['app_installs', [
                'snapchat' => ['spend' => 350.0, 'impressions' => 120000.0, 'clicks' => 4000.0, 'installs' => 120.0],
                'tiktok' => ['spend' => 150.0, 'impressions' => 50000.0, 'clicks' => 1500.0, 'installs' => 30.0],
            ], ['spend', 'installs', 'cpi']],
        ];
    }

    /**
     * @param  array<string, array<string, float>>  $perProvider
     * @param  list<string>  $keys
     */
    #[DataProvider('objectives')]
    public function test_the_digest_totals_and_platform_rows_equal_the_live_report_for_the_same_project_and_window(string $objective, array $perProvider, array $keys): void
    {
        ['project' => $project, 'accounts' => $accounts] = $this->seedProject('P-'.$objective, $objective, $perProvider);

        $digest = app(DailyDigest::class)->buildRange($this->operator, (string) $this->tenant->getKey(), [(string) $project->getKey()], Carbon::parse(self::FROM)->startOfDay(), Carbon::parse(self::TO)->endOfDay());
        $this->assertTrue($digest['sendable']);
        $model = OperationalDigestModel::project($digest['projects'][0], 'ar', 30);

        $created = $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$project->getKey()}/reports/live", [
                'name' => 'Live', 'account_ids' => $accounts, 'from' => self::FROM, 'to' => self::TO, 'form' => 'detailed',
                'metrics' => ['spend', 'impressions', 'clicks'], 'hide_spend' => false,
            ])->assertCreated()->json('data');
        $live = $this->getJson('/api/v1/reports/shared/'.$created['token'].'/live')->assertOk()->json('data');

        // The project's totals, on every figure the objective is judged on and the common ones.
        foreach (array_unique([...$keys, 'impressions', 'clicks']) as $key) {
            if (! isset($live['totals'][$key])) {
                continue;
            }
            $this->assertEqualsWithDelta((float) $live['totals'][$key], (float) ($model['raw']['totals'][$key] ?? -1), 0.01, "{$objective}: total {$key} is not the report's");
        }

        // Each platform's row.
        $liveRows = [];
        foreach ($live['platforms'] as $row) {
            $liveRows[(string) $row['provider']] = $row;
        }
        $this->assertSame(array_keys($perProvider), array_values(array_intersect(array_keys($perProvider), array_keys($model['raw']['platforms']))), "{$objective}: a seeded platform is missing from the digest");
        foreach ($perProvider as $provider => $metrics) {
            $this->assertArrayHasKey($provider, $liveRows);
            foreach (array_keys($metrics) as $key) {
                // The seeded value is the ground truth; the report's row carries the keys it carries (spend, impressions, clicks…), and where it has one it must agree.
                $this->assertEqualsWithDelta($metrics[$key], (float) $model['raw']['platforms'][$provider][$key], 0.01, "{$objective}/{$provider}: {$key} is not what the platform sent");
                if (isset($liveRows[$provider][$key])) {
                    $this->assertEqualsWithDelta((float) $liveRows[$provider][$key], (float) $model['raw']['platforms'][$provider][$key], 0.01, "{$objective}/{$provider}: {$key} is not the report's");
                }
            }
        }

        // The rendered email carries each platform by its Arabic name, with the objective's own figures.
        $html = (new OperationalDigestMail($model, 'ar', 'م', 'monthly', 'project'))->render();
        foreach (array_keys($perProvider) as $provider) {
            $this->assertStringContainsString('data-provider="'.$provider.'"', $html);
        }
        $this->assertStringContainsString(number_format((float) $perProvider[array_key_first($perProvider)]['spend']), $html);
    }

    public function test_a_project_with_no_figures_says_no_data_and_never_zero(): void
    {
        $seeded = $this->seedProject('Quiet', 'sales', ['meta' => ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 80.0]]);
        $digest = app(DailyDigest::class)->buildRange($this->operator, (string) $this->tenant->getKey(), [(string) $seeded['project']->getKey()], Carbon::parse(self::FROM)->startOfDay(), Carbon::parse(self::TO)->endOfDay());
        // The lens is inferred from declared objectives; this test is about the sales set, so say so.
        $digest['projects'][0]['objective'] = 'sales';
        $model = OperationalDigestModel::project($digest['projects'][0], 'ar', 30);

        // A sales project whose only platform sent no orders and no revenue: the cells say «لا بيانات», not «0».
        $byKey = array_column($model['platforms'][0]['figures'], null, 'key');
        foreach (['purchases', 'revenue', 'roas'] as $key) {
            $this->assertTrue($byKey[$key]['missing'], "{$key} was drawn as a figure though nobody sent it");
            $this->assertSame('لا بيانات', $byKey[$key]['value']);
        }
        $this->assertFalse($byKey['spend']['missing']);

        $html = (new OperationalDigestMail($model, 'ar', 'م', 'weekly', 'project'))->render();
        $this->assertStringContainsString('لا بيانات', $html);
    }

    public function test_a_digest_never_carries_another_tenants_project(): void
    {
        $mine = $this->seedProject('Mine', 'sales', ['meta' => ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 80.0]]);

        // Another agency, with a project of its own.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $other->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $other->getKey(), 'name' => 'OC', 'slug' => 'oc-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $theirs = Project::create(['tenant_id' => $other->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'SECRET-OTHER-CLIENT', 'status' => 'active']);

        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        // Even handed the other tenant's id, the builder answers only for the recipient's own tenant.
        $digest = app(DailyDigest::class)->buildRange($this->operator, (string) $this->tenant->getKey(), [(string) $mine['project']->getKey(), (string) $theirs->getKey()], Carbon::parse(self::FROM)->startOfDay(), Carbon::parse(self::TO)->endOfDay());

        $html = (new OperationalDigestMail(OperationalDigestModel::portfolio($digest, 'ar', 'monthly'), 'ar', 'م', 'monthly', 'portfolio'))->render();
        $this->assertStringContainsString('Mine', $html);
        $this->assertStringNotContainsString('SECRET-OTHER-CLIENT', $html);
        $this->assertCount(1, $digest['projects']);
    }
}
