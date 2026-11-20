<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Support\CreativeKind;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Reports\Services\ReportAds;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * CONTENT-CARDS-001 — the standing of each piece of content among content of the SAME objective: its rank, the report's
 * «الأفضل أداءً» / «محتوى بفرصة للتحسين» class, and the objective's own result. Pinned against figures worked by hand.
 */
final class ContentStandingTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private ExternalAccount $meta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
        Cache::flush();
        $this->meta = $this->account($this->agency, 'meta', $this->projectA);
    }

    public function test_rank_and_class_follow_the_reports_ranking_inside_one_objective(): void
    {
        $sales = $this->campaign($this->projectA, 'sales');
        // Seven creatives, each spending 100 (material), returning 100..700: ROAS 1..7. Orders 1..7.
        $ids = [];
        foreach (range(1, 7) as $n) {
            $ids[$n] = $this->creative($this->meta, $sales, $this->projectA, 'image', ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 100.0, 'conversions' => (float) $n, 'revenue' => 100.0 * $n]);
        }
        // Another objective in the same scope never enters the sales order.
        $traffic = $this->campaign($this->projectA, 'traffic');
        $t = $this->creative($this->meta, $traffic, $this->projectA, 'image', ['spend' => 50.0, 'impressions' => 5000.0, 'clicks' => 500.0]);

        $items = $this->items($this->ask($this->owner()));

        $this->assertSame(1, $items[$ids[7]]['rank']);
        $this->assertSame(7, $items[$ids[1]]['rank']);
        $this->assertSame(7, $items[$ids[4]]['of']);
        $this->assertSame('roas', $items[$ids[4]]['metric']);
        // The report's headline lists: the best five of the order, then the weakest five that are not among them.
        foreach ([7, 6, 5, 4, 3] as $n) {
            $this->assertSame('best', $items[$ids[$n]]['class'], "ROAS {$n}");
        }
        foreach ([2, 1] as $n) {
            $this->assertSame('improve', $items[$ids[$n]]['class'], "ROAS {$n}");
        }
        // The objective's own result: orders, for a sales ad.
        $this->assertEquals(7.0, $items[$ids[7]]['result']['value']);
        $this->assertSame('الطلبات', $items[$ids[7]]['result']['label_ar']);
        $this->assertSame('traffic', $items[$t]['family']);
        $this->assertSame(1, $items[$t]['of'], 'the traffic ad is ranked among traffic ads only');
    }

    public function test_the_class_is_exactly_what_the_live_report_lists_for_the_same_content_and_period(): void
    {
        $sales = $this->campaign($this->projectA, 'sales');
        $ids = [];
        foreach (range(1, 9) as $n) {
            $ids[$n] = $this->creative($this->meta, $sales, $this->projectA, 'image', ['spend' => 100.0 + $n, 'impressions' => 5000.0, 'clicks' => 100.0, 'conversions' => (float) $n, 'revenue' => 110.0 * $n]);
        }
        // Awareness content in the same project must not disturb the sales lists.
        $this->creative($this->meta, $this->campaign($this->projectA, 'awareness'), $this->projectA, 'image', ['spend' => 900.0, 'impressions' => 400000.0, 'clicks' => 100.0]);

        $report = app(ReportAds::class)->for('sales', Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), ['project_ids' => [(string) $this->projectA->id]]);
        $items = $this->items($this->ask($this->owner()));

        $reportBest = array_column($report['ads'], 'id');
        $reportWorst = array_column($report['worst'], 'id');
        $this->assertNotSame([], $reportBest);

        // The report was asked for the sales objective, so the comparison is on the sales family; the awareness ad has its own lists.
        $salesItems = array_filter($items, static fn (array $i): bool => $i['family'] === 'sales');
        $best = array_keys(array_filter($salesItems, static fn (array $i): bool => $i['class'] === 'best'));
        $improve = array_keys(array_filter($salesItems, static fn (array $i): bool => $i['class'] === 'improve'));
        $this->assertEqualsCanonicalizing($reportBest, $best, 'best = the report\'s «أفضل المحتوى أداءً»');
        $this->assertEqualsCanonicalizing(array_values(array_diff($reportWorst, $reportBest)), $improve, 'improve = the report\'s «محتوى بفرصة للتحسين», minus what is already among the best');
        // The order itself: rank 1 is the report's first.
        $this->assertSame($reportBest[0], array_search(1, array_column($salesItems, 'rank', 'id'), true));
    }

    public function test_content_that_cannot_be_judged_has_no_rank_and_no_class_not_a_last_place(): void
    {
        $sales = $this->campaign($this->projectA, 'sales');
        $quiet = $this->creative($this->meta, $sales, $this->projectA, 'image', ['spend' => 0.0, 'impressions' => 10.0, 'clicks' => 0.0]);
        $this->creative($this->meta, $sales, $this->projectA, 'image', ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 100.0, 'conversions' => 4.0, 'revenue' => 400.0]);
        $unknown = $this->creative($this->meta, $this->campaign($this->projectA, 'unmapped_thing'), $this->projectA, 'image', ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 100.0]);

        $items = $this->items($this->ask($this->owner()));

        $this->assertNull($items[$quiet]['rank']);
        $this->assertNull($items[$quiet]['class']);
        $this->assertSame('unknown', $items[$unknown]['family']);
        $this->assertNull($items[$unknown]['rank']);
        $this->assertNull($items[$unknown]['result']['value'], 'no objective, no result — never a guess');
    }

    public function test_the_why_rows_carry_the_fields_contentwhy_reads_over_the_whole_scope(): void
    {
        $sales = $this->campaign($this->projectA, 'sales');
        $id = $this->creative($this->meta, $sales, $this->projectA, 'image', ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 100.0, 'conversions' => 4.0, 'revenue' => 400.0]);

        $data = $this->ask($this->owner())['data'];
        $row = collect($data['why_rows'])->firstWhere('id', $id);

        $this->assertEquals(5000.0, $row['metrics']['impressions']);
        $this->assertEquals(100.0, $row['metrics']['clicks']);
        $this->assertSame('sales', $row['objective']);
        $this->assertContains($row['fatigue']['status'], ['improving', 'stable', 'watch', 'fatigued', 'insufficient_data']);
        $this->assertSame(1, $data['read']);
        $this->assertSame(1, $data['in_scope']);
    }

    public function test_another_project_and_another_tenant_never_enter_and_a_scoped_member_sees_only_their_client(): void
    {
        $mineCampaign = $this->campaign($this->projectA, 'sales');
        $mine = $this->creative($this->meta, $mineCampaign, $this->projectA, 'image', ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 50.0, 'conversions' => 2.0, 'revenue' => 200.0]);

        $accountB = $this->account($this->agency, 'meta', $this->projectB);
        $theirs = $this->creative($accountB, $this->campaign($this->projectB, 'sales'), $this->projectB, 'image', ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 50.0, 'conversions' => 9.0, 'revenue' => 900.0]);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'oth-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);

        $member = $this->scopedTo($this->clientA, ['campaigns.view', 'projects.view', 'projects.view.all']);
        ProjectMembership::create(['tenant_id' => $this->agency->id, 'project_id' => $this->projectA->id, 'user_id' => $member->id, 'role' => ProjectRole::MARKETING_MANAGER, 'status' => 'active']);

        $ids = array_keys($this->items($this->ask($member)));
        $this->assertSame([$mine], $ids);
        $this->assertNotContains($theirs, $ids);

        // The unrestricted reader sees both, and the order is within each... objective, across the reach.
        $all = $this->items($this->ask($this->owner()));
        $this->assertArrayHasKey($theirs, $all);
        $this->assertSame(2, $all[$mine]['of']);
        $this->assertNotNull($other->id);
    }

    public function test_it_needs_campaign_view(): void
    {
        $nobody = $this->operatorIn($this->agency, ['projects.view']);
        $this->actingAs($nobody, 'sanctum')->getJson('/api/v1/content-standing')->assertForbidden();
    }

    public function test_the_text_filter_finds_ads_with_no_media_and_the_kinds_stay_as_they_were(): void
    {
        $sales = $this->campaign($this->projectA, 'sales');
        $m = ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 100.0];
        $text = $this->creative($this->meta, $sales, $this->projectA, 'text', $m);
        $textNoToken = $this->creative($this->meta, $sales, $this->projectA, null, $m);
        $image = $this->creative($this->meta, $sales, $this->projectA, 'image', $m, ['asset_url' => 'https://cdn.example/a.png']);
        $film = $this->creative($this->meta, $sales, $this->projectA, 'SNAP_AD', $m, ['video_url' => 'https://cdn.example/a.mp4']);

        $this->assertEqualsCanonicalizing([$text, $textNoToken], $this->idsOf(['kinds[]' => 'text']));
        $this->assertSame([$image], $this->idsOf(['kinds[]' => 'image']));
        $this->assertSame([$film], $this->idsOf(['kinds[]' => 'video']));
        $this->assertSame(['image', 'video', 'carousel', 'collection', 'catalog'], CreativeKind::ALL, 'the card vocabulary is unchanged');
        $this->assertContains('text', CreativeKind::FILTERABLE);
    }

    public function test_query_count_does_not_grow_with_the_number_of_creatives_and_the_second_read_is_cached(): void
    {
        $sales = $this->campaign($this->projectA, 'sales');
        $make = fn (int $n) => array_map(fn (int $i) => $this->creative($this->meta, $sales, $this->projectA, 'image', ['spend' => 100.0, 'impressions' => 5000.0, 'clicks' => 100.0, 'conversions' => (float) ($i + 1), 'revenue' => 100.0 * ($i + 1)]), range(1, $n));

        $make(3);
        $few = $this->countQueries(fn () => $this->ask($this->owner()));
        Cache::flush();
        $make(20);
        $many = $this->countQueries(fn () => $this->ask($this->owner()));
        $this->assertLessThanOrEqual($few + 2, $many, "{$few} queries for 3 creatives, {$many} for 23");

        $owner = $this->owner();
        $this->ask($owner);
        $cached = $this->countQueries(fn () => $this->ask($owner));
        $this->assertLessThan($many - 5, $cached, 'the second identical read comes from the per-tenant cache');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function ask(User $user, string $query = ''): array
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/v1/content-standing?from='.Carbon::parse('2026-09-01')->toDateString().'&to='.Carbon::parse('2026-09-30')->toDateString().$query)->assertOk()->json();
    }

    /** @return array<string, array<string, mixed>> */
    private function items(array $response): array
    {
        return collect($response['data']['items'])->keyBy('id')->all();
    }

    /** @param array<string, string> $params @return list<string> */
    private function idsOf(array $params): array
    {
        return array_keys($this->items($this->ask($this->owner(), '&'.http_build_query($params))));
    }

    private function countQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    private function account(Tenant $tenant, string $provider, Project $project): ExternalAccount
    {
        app(TenantContext::class)->setTenantId((string) $tenant->getKey());
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $tenant->getKey(), provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider.uniqid(),
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'a-'.uniqid(),
            'name' => $provider, 'currency' => 'SAR', 'status' => 'active',
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(), 'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->getKey(), 'external_account_id' => $account->getKey(),
            'provider' => $provider, 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return $account;
    }

    private function campaign(Project $project, string $objective): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->agency->getKey(), 'project_id' => $project->getKey(),
            'name' => 'C-'.uniqid(), 'status' => 'active', 'objective' => $objective,
        ]);
    }

    /**
     * @param  array<string, float|string|null>  $metrics
     * @param  array<string, mixed>  $extra
     */
    private function creative(ExternalAccount $account, UnifiedCampaign $campaign, Project $project, ?string $format, array $metrics, array $extra = []): string
    {
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->agency->getKey(), 'project_id' => $project->getKey(),
            'external_account_id' => $account->getKey(), 'unified_campaign_id' => $campaign->getKey(),
            'provider' => $account->provider, 'external_id' => 'ec-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->agency->getKey(), 'project_id' => $project->getKey(),
            'campaign_id' => $campaign->getKey(), 'external_campaign_id' => $external->getKey(),
            'external_account_id' => $account->getKey(), 'provider' => $account->provider,
            'external_id' => 'cr-'.uniqid(), 'external_creative_id' => 'xc-'.uniqid(), 'format' => $format, 'name' => 'Creative '.Str::random(4),
        ] + $extra);
        DB::table('creative_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->agency->getKey(), 'project_id' => $project->getKey(),
            'creative_id' => (string) $creative->getKey(), 'campaign_id' => $campaign->getKey(), 'metric_date' => '2026-09-15',
            'created_at' => now(), 'updated_at' => now(),
        ], $metrics));

        return (string) $creative->getKey();
    }
}
