<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsBoundaryWorld;
use Tests\TestCase;

/**
 * INTEG-UNIFIED-BOUNDARIES-001 (case 5) — the SAME provider campaign id in two accounts, and in two projects: composite-key
 * scoping, no merge, no cross-read.
 *
 * A provider's campaign id is unique inside ONE ad account, not across accounts (an agency's accounts routinely hold campaigns the
 * platform numbered alike, and Production has 260 campaign ids that appear under more than one account). Anything that treats the
 * bare id as an identity merges two clients' figures. Three accounts here all hold a campaign `cmp-SAME`:
 *
 *   a1 and a2 -> project P1 (two accounts in ONE project), a3 -> project P2 (another project of the same client workspace).
 *
 * Written against, and asserted at: the WRITER (`AccountMetricsSyncer`: daily and entity grain, each row keyed by its own account's
 * campaign), then every READER — project metrics, campaign list, client analytics, the public live report and its CSV/XLSX.
 * `CreativeSharedIdAcrossAccountsTest` already covers creatives; this is campaigns, ad sets, ads and their metrics.
 */
final class IntegrationBoundarySameCampaignIdTest extends TestCase
{
    use BuildsBoundaryWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private Project $p1;

    private Project $p2;

    private User $operator;

    /** @var array<string, ExternalAccount> */
    private array $account = [];

    /** Spend per account the faked provider answers with. @var array<string, float> */
    private array $answers = ['act_1' => 100.0, 'act_2' => 10.0, 'act_3' => 1000.0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        $this->bwConfigure('meta');
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

        $this->tenant = $this->bwTenant();
        $workspace = $this->bwWorkspace($this->tenant);
        $this->p1 = $this->bwProject($this->tenant, $workspace, 'P1');
        $this->p2 = $this->bwProject($this->tenant, $workspace, 'P2');
        $this->operator = $this->bwOperator($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── The writer ────────────────────────────────────────────────────────────────────────────

    public function test_a_sync_files_each_accounts_figures_under_its_own_campaign_even_when_the_provider_ids_are_equal(): void
    {
        $this->world(withFigures: false);
        $this->fakeMeta();

        foreach (['act_1', 'act_2', 'act_3'] as $id) {
            app(AccountMetricsSyncer::class)->sync($this->account[$id]->refresh(), Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));
        }

        $spend = DB::table('daily_metrics')->where('metric_key', 'spend')->get();
        $this->assertCount(3, $spend, 'one spend row per account: equal campaign ids must not merge');

        foreach (['act_1' => [$this->p1, 100.0], 'act_2' => [$this->p1, 10.0], 'act_3' => [$this->p2, 1000.0]] as $external => [$project, $expected]) {
            $own = ExternalCampaign::withoutGlobalScopes()->where('external_account_id', $this->account[$external]->id)->where('external_id', 'cmp-SAME')->firstOrFail();
            $row = $spend->firstWhere('external_account_id', $this->account[$external]->id);
            $this->assertNotNull($row, "{$external} wrote no spend row");
            $this->assertSame($own->id, $row->external_campaign_id, "{$external}'s figure was filed under another account's campaign");
            $this->assertSame($project->id, $row->project_id);
            $this->assertEqualsWithDelta($expected, (float) $row->value, 0.001);
        }

        // Ad-set and ad grain: the same ids again (adset-SAME, ad-SAME) in each account; each row keeps its own account's entity.
        $entities = DB::table('entity_daily_metrics')->whereIn('entity_type', ['ad_set', 'ad'])->get();
        $this->assertCount(6, $entities, 'ad set + ad, per account');
        foreach (['act_1' => 100.0, 'act_2' => 10.0, 'act_3' => 1000.0] as $external => $expected) {
            foreach (['ad_set', 'ad'] as $type) {
                $row = $entities->where('external_account_id', $this->account[$external]->id)->firstWhere('entity_type', $type);
                $this->assertNotNull($row, "{$external} {$type}");
                $this->assertEqualsWithDelta($expected, (float) $row->spend, 0.001, "{$external} {$type} carries another account's figure");
                $entityModel = $type === 'ad_set' ? ExternalAdSet::withoutGlobalScopes() : ExternalAd::withoutGlobalScopes();
                $this->assertSame(
                    $this->account[$external]->id,
                    (string) ExternalCampaign::withoutGlobalScopes()->whereKey($entityModel->findOrFail($row->entity_id)->external_campaign_id)->value('external_account_id'),
                    "{$external} {$type} is filed under another account's entity",
                );
            }
        }

        // Re-syncing one account restates only its own rows.
        $this->answers['act_1'] = 150.0;
        app(AccountMetricsSyncer::class)->sync($this->account['act_1']->refresh(), Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));
        $this->assertEqualsWithDelta(150.0, (float) DB::table('daily_metrics')->where('external_account_id', $this->account['act_1']->id)->where('metric_key', 'spend')->value('value'), 0.001);
        $this->assertEqualsWithDelta(10.0, (float) DB::table('daily_metrics')->where('external_account_id', $this->account['act_2']->id)->where('metric_key', 'spend')->value('value'), 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) DB::table('daily_metrics')->where('external_account_id', $this->account['act_3']->id)->where('metric_key', 'spend')->value('value'), 0.001);
    }

    // ── The readers ───────────────────────────────────────────────────────────────────────────

    public function test_every_reader_keeps_the_equal_campaign_ids_apart_and_never_crosses_a_project(): void
    {
        $this->world(withFigures: true);
        // Nothing may reach a provider from a read: a period-reach ask is answered empty, and anything else is a stray request.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        // 2 days each: a1 200, a2 20, a3 2000.

        // Client analytics: the whole workspace, each campaign once.
        $client = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/app/clients/'.$this->p1->client_workspace_id.'/analytics?from=2026-08-01&to=2026-08-31')->assertOk()->json('data');
        $this->assertEqualsWithDelta(2220.0, (float) ($client['totals']['spend'] ?? -1), 0.001, 'client analytics merged or dropped a campaign that shares an id');

        // Dashboard / project metrics.
        $p1 = $this->asOperator($this->p1, 'metrics/summary?from=2026-08-01&to=2026-08-31');
        $p2 = $this->asOperator($this->p2, 'metrics/summary?from=2026-08-01&to=2026-08-31');
        $this->assertEqualsWithDelta(220.0, (float) ($p1['current']['spend'] ?? -1), 0.001, 'P1 is the sum of its own two accounts, not merged and not joined by P2');
        $this->assertEqualsWithDelta(2000.0, (float) ($p2['current']['spend'] ?? -1), 0.001, 'P2 shows another project\'s spend');

        // The campaign list: two rows for P1 (equal provider ids, different accounts), one for P2.
        $rows1 = $this->asOperator($this->p1, 'metrics/campaigns?from=2026-08-01&to=2026-08-31');
        $rows2 = $this->asOperator($this->p2, 'metrics/campaigns?from=2026-08-01&to=2026-08-31');
        $this->assertCount(2, $rows1, 'two accounts\' campaigns with one provider id were merged into one row');
        $this->assertEqualsWithDelta(220.0, (float) collect($rows1)->sum(fn (array $r): float => (float) ($r['spend'] ?? 0)), 0.001);
        $this->assertEqualsWithDelta(200.0, (float) collect($rows1)->max(fn (array $r): float => (float) ($r['spend'] ?? 0)), 0.001);
        $this->assertCount(1, $rows2);
        $this->assertEqualsWithDelta(2000.0, (float) ($rows2[0]['spend'] ?? -1), 0.001);
        $this->assertStringNotContainsString('SameThree', (string) json_encode($rows1), 'P1 names a campaign of P2');
        $this->assertStringNotContainsString('SameOne', (string) json_encode($rows2), 'P2 names a campaign of P1');
        $this->assertStringNotContainsString('SameTwo', (string) json_encode($rows2));

        // The report builder's pickers offer each project only its own campaigns.
        $offered1 = array_column($this->asOperator($this->p1, 'reports/scope/options?axis=campaigns')['campaigns'] ?? [], 'id');
        $this->assertCount(2, $offered1);
        $this->assertNotContains($this->rows['act_3']['unified'], $offered1);

        // The public live report and its files, for each project.
        foreach ([[$this->p1, 220.0, ['SameThree']], [$this->p2, 2000.0, ['SameOne', 'SameTwo']]] as [$project, $expected, $foreign]) {
            $token = $this->bwLiveLink($project, $this->operator, ['meta']);
            $page = $this->getJson("/api/v1/reports/shared/{$token}/live?from=2026-08-01&to=2026-08-31")->assertOk()->json('data');
            $this->assertEqualsWithDelta($expected, (float) ($page['totals']['spend'] ?? -1), 0.001, "{$project->name}: the live report's spend");
            $names = array_map(fn ($r) => (string) ($r['name'] ?? ''), (array) $page['campaign_rows']);
            $this->assertCount($project->is($this->p1) ? 2 : 1, $names, "{$project->name}: campaign rows");
            foreach ($foreign as $name) {
                $this->assertStringNotContainsString($name, (string) json_encode($page), "{$project->name}'s report names {$name}");
            }

            $csv = $this->get("/api/v1/reports/shared/{$token}/download/csv")->assertOk()->streamedContent();
            $this->assertMatchesRegularExpression('/^spend,'.preg_quote((string) (int) $expected, '/').'(\.0+)?$/m', $csv, "{$project->name}: the CSV's spend");
            foreach ($foreign as $name) {
                $this->assertStringNotContainsString($name, $csv);
            }
            $this->get("/api/v1/reports/shared/{$token}/download/xlsx")->assertOk();
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    /** @var array<string, array{campaign: string, unified: string, ad: string, creative: string, name: string}> */
    private array $rows = [];

    private function world(bool $withFigures): void
    {
        $connection = $this->bwConnection($this->tenant, 'meta');
        foreach (['act_1' => [$this->p1, 'SameOne', 100.0], 'act_2' => [$this->p1, 'SameTwo', 10.0], 'act_3' => [$this->p2, 'SameThree', 1000.0]] as $id => [$project, $name, $spend]) {
            $this->account[$id] = $this->bwAccount($connection, $id);
            $this->bwBind($this->account[$id], $project);

            if ($withFigures) {
                $this->rows[$id] = $this->bwFigures($this->account[$id], $project, 'cmp-SAME', $spend, $name);

                continue;
            }

            // The structure sync has already discovered a campaign / ad set / ad whose PROVIDER ids are equal in every account.
            $campaign = ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $this->account[$id]->id,
                'provider' => 'meta', 'external_id' => 'cmp-SAME', 'name' => $name, 'status' => 'active',
            ]);
            $adSet = ExternalAdSet::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_campaign_id' => $campaign->id,
                'provider' => 'meta', 'external_id' => 'adset-SAME', 'name' => $name.' set', 'status' => 'active',
            ]);
            ExternalAd::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_ad_set_id' => $adSet->id,
                'external_campaign_id' => $campaign->id, 'provider' => 'meta', 'external_id' => 'ad-SAME', 'name' => $name.' ad', 'status' => 'active',
            ]);
        }
    }

    /** The provider answers per account, from the account named in the URL, with the SAME ids everywhere. */
    private function fakeMeta(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());
            if (! preg_match('#/(act_\d)/#', $url, $m) || ! str_contains($url, '/insights')) {
                return Http::response(['data' => []], 200);
            }
            $row = ['campaign_id' => 'cmp-SAME', 'date_start' => '2026-08-01', 'spend' => (string) $this->answers[$m[1]], 'impressions' => '1000', 'clicks' => '10'];

            if (str_contains($url, 'level=adset')) {
                return Http::response(['data' => [[...$row, 'adset_id' => 'adset-SAME']]], 200);
            }
            if (str_contains($url, 'level=ad')) {
                return Http::response(['data' => [[...$row, 'ad_id' => 'ad-SAME', 'adset_id' => 'adset-SAME']]], 200);
            }

            return Http::response(['data' => [$row]], 200);
        });
    }

    /** @return array<mixed> */
    private function asOperator(Project $project, string $path): array
    {
        return $this->bwAsOperator($this->operator, $project, $path);
    }
}
