<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §15.10 — the insights and the recommendations, and the ways they could be worthless.
 *
 * The failure mode this class exists to prevent is not a wrong number. It is a report full of
 * sentences that are true of every account on every day — «test new creative», «monitor performance»
 * — which teaches a client that the analysis section is decoration. So the claims pinned here are
 * mostly NEGATIVE: what must NOT produce a finding.
 */
final class CreativeInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private Project $project;

    private UnifiedCampaign $awareness;

    private UnifiedCampaign $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Ins', 'slug' => 'ins-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op@insights.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->awareness = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Brand',
            'objective' => 'awareness', 'status' => 'active',
        ]);
        $this->sales = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale',
            'objective' => 'sales', 'status' => 'active',
        ]);
    }

    // ---- fixtures -----------------------------------------------------------------------------

    private function creative(array $over = []): ExternalCreative
    {
        return ExternalCreative::create(array_merge([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'campaign_id' => $this->sales->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => 'A creative',
            'format' => 'image',
            'status' => 'active',
            'last_active_at' => now(),
            'last_synced_at' => now(),
        ], $over));
    }

    private function day(ExternalCreative $creative, string $date, array $values): void
    {
        DB::table('creative_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'campaign_id' => $creative->campaign_id,
            'metric_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ], $values));
    }

    private function now(ExternalCreative $creative, array $values): void
    {
        $this->day($creative, now()->subDays(2)->toDateString(), $values);
    }

    private function before(ExternalCreative $creative, array $values): void
    {
        $this->day($creative, now()->subDays(35)->toDateString(), $values);
    }

    /** @return list<array<string, mixed>> */
    private function insights(string $extra = ''): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives/pulse?from='.now()->subDays(29)->toDateString()
                .'&to='.now()->toDateString().$extra)
            ->assertOk()
            ->json('data.insights.items');
    }

    /** @param list<array<string, mixed>> $items */
    private function keys(array $items): array
    {
        return array_values(array_unique(array_column($items, 'key')));
    }

    // ---- movement against the previous period -------------------------------------------------

    /**
     * A falling click-through is reported with both figures, the window behind them, and a next step.
     *
     * The figures are asserted INSIDE the sentence, not only in the payload: a recommendation whose
     * text is generic while its data is specific is still a generic recommendation, because the text
     * is what the client reads.
     */
    public function test_a_falling_click_through_is_named_with_both_figures_and_an_action(): void
    {
        $creative = $this->creative(['name' => 'Tired banner']);
        $this->before($creative, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 3000]);
        $this->now($creative, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 2000]);

        $found = collect($this->insights())->firstWhere('key', 'ctr_decline');

        $this->assertNotNull($found, 'a 33% fall in CTR should be reported');
        $this->assertSame('warning', $found['severity']);
        $this->assertSame('previous_period', $found['comparison']);
        $this->assertSame('Tired banner', $found['creative_name']);
        $this->assertSame('sales', $found['objective']);
        $this->assertSame('conversion', $found['path']);
        $this->assertSame('meta', $found['provider']);
        $this->assertSame('Sale', $found['campaign_name']);

        // Both figures, in the sentence the reader sees, in Latin digits.
        $this->assertStringContainsString('3.00%', $found['detail_en']);
        $this->assertStringContainsString('2.00%', $found['detail_en']);
        $this->assertStringContainsString('3.00%', $found['detail_ar']);

        $this->assertSame(-0.3333, $found['movement']['change']);
        $this->assertSame(0.02, round($found['supporting_metrics']['ctr'], 4));
        $this->assertSame(0.03, round($found['previous_metrics']['ctr'], 4));
        $this->assertSame('high', $found['confidence']);
        $this->assertNotSame('', trim($found['action_ar']));
        $this->assertNotSame('', trim($found['action_en']));

        // The window is on the item, so an exported row can be read without the page around it.
        $this->assertSame(now()->subDays(29)->toDateString(), $found['period']['from']);
        $this->assertSame(now()->subDays(30)->toDateString(), $found['previous_period']['to']);
    }

    /**
     * A small movement is not a finding.
     *
     * The single most important negative in this file: an engine that reports every wobble produces a
     * page of noise, and the reader stops distinguishing it from the page of signal.
     */
    public function test_ordinary_variance_produces_nothing(): void
    {
        $creative = $this->creative();
        $this->before($creative, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 3000]);
        $this->now($creative, ['spend' => 1010, 'impressions' => 100000, 'clicks' => 2900]);

        $this->assertSame([], $this->insights(), 'a 3% drift is variance, not an insight');
    }

    /** An awareness creative is never handed a cost-per-order finding. */
    public function test_an_awareness_creative_is_never_judged_on_cost_per_order(): void
    {
        $creative = $this->creative(['campaign_id' => $this->awareness->getKey(), 'name' => 'Reach film']);
        // Conversions ARE present and their cost doubled — on the sales path this would fire.
        $this->before($creative, ['spend' => 500, 'impressions' => 200000, 'clicks' => 2000, 'conversions' => 50]);
        $this->now($creative, ['spend' => 1000, 'impressions' => 200000, 'clicks' => 2000, 'conversions' => 25]);

        $this->assertNotContains('cpa_increase', $this->keys($this->insights()));
    }

    /** Scaling needs BOTH halves: growing spend AND a cost that held. */
    public function test_scaling_is_reported_only_when_the_cost_actually_held(): void
    {
        $steady = $this->creative(['name' => 'Steady']);
        $this->before($steady, ['spend' => 1000, 'impressions' => 50000, 'clicks' => 1000, 'conversions' => 50]);
        $this->now($steady, ['spend' => 1500, 'impressions' => 75000, 'clicks' => 1500, 'conversions' => 75]);

        $slipping = $this->creative(['name' => 'Slipping']);
        $this->before($slipping, ['spend' => 1000, 'impressions' => 50000, 'clicks' => 1000, 'conversions' => 50]);
        $this->now($slipping, ['spend' => 1500, 'impressions' => 75000, 'clicks' => 1500, 'conversions' => 40]);

        $items = collect($this->insights())->where('key', 'cpa_stable_while_scaling');

        $this->assertSame(['Steady'], $items->pluck('creative_name')->values()->all());
        $this->assertSame('opportunity', $items->first()['severity']);
    }

    /**
     * Frequency is judged on where it IS, not on whether it moved.
     *
     * A creative that has sat at 4.2 all month has not been «stable»; it has been shown to the same
     * people four times over, and a rule that only watched movement would have said nothing about it.
     */
    public function test_saturated_frequency_is_reported_even_when_it_did_not_move(): void
    {
        $creative = $this->creative(['name' => 'Everywhere']);
        $this->before($creative, ['spend' => 900, 'impressions' => 60000, 'clicks' => 600, 'frequency' => 4.2, 'reach' => 14000]);
        $this->now($creative, ['spend' => 900, 'impressions' => 60000, 'clicks' => 600, 'frequency' => 4.2, 'reach' => 14000]);

        $found = collect($this->insights())->firstWhere('key', 'frequency_saturation');

        $this->assertNotNull($found);
        $this->assertSame(4.2, $found['movement']['current']);
        $this->assertSame(0.0, (float) $found['movement']['change']);
        $this->assertStringContainsString('4.20', $found['detail_en']);
    }

    // ---- the evidence floor -------------------------------------------------------------------

    /**
     * Below the floor, one finding fires and it says so — «insufficient» is a verdict, not a silence.
     *
     * The ratios of a creative with 400 impressions are not small measurements, they are not
     * measurements; but money going into it is a fact worth a client's attention, and the honest
     * sentence is «we cannot yet say, and it is costing you».
     */
    public function test_a_thin_creative_is_reported_as_unjudgeable_rather_than_as_performing(): void
    {
        $creative = $this->creative(['name' => 'Barely ran']);
        $this->before($creative, ['spend' => 40, 'impressions' => 300, 'clicks' => 30]);
        $this->now($creative, ['spend' => 260, 'impressions' => 400, 'clicks' => 6]);

        $items = $this->insights();

        $this->assertSame(['spend_without_evidence'], $this->keys($items));
        $this->assertSame('insufficient_data', $items[0]['confidence']);
        $this->assertStringContainsString('400.00', $items[0]['detail_en']);
        // Never dressed up as an assessment of how it performed.
        $this->assertStringNotContainsString('performing', strtolower($items[0]['title_en']));
    }

    /** A thin creative that spent nothing is not news. */
    public function test_a_thin_creative_that_cost_nothing_is_not_reported(): void
    {
        $creative = $this->creative();
        $this->now($creative, ['spend' => 0, 'impressions' => 400, 'clicks' => 6]);

        $this->assertSame([], $this->insights());
    }

    // ---- against peers on the same path -------------------------------------------------------

    /**
     * Strong clicks with weak conversion is found by comparing with the SAME path's median.
     *
     * And the peer group has to exist: with fewer than three evidenced creatives on the path, the
     * «median» is one creative's opinion of normal, so no peer rule fires at all.
     */
    public function test_a_click_to_conversion_mismatch_is_found_against_same_path_peers(): void
    {
        // Four evidenced sales creatives: three ordinary, one that clicks well and converts badly.
        foreach (range(1, 3) as $i) {
            $peer = $this->creative(['name' => "Peer {$i}"]);
            $this->now($peer, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 2000, 'conversions' => 100, 'revenue' => 9000]);
        }

        $odd = $this->creative(['name' => 'Clickbait']);
        $this->now($odd, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 6000, 'conversions' => 60, 'revenue' => 5400]);

        $found = collect($this->insights())->firstWhere('key', 'strong_ctr_weak_conversion');

        $this->assertNotNull($found);
        $this->assertSame('Clickbait', $found['creative_name']);
        $this->assertSame('peers', $found['comparison']);
        $this->assertSame('opportunity', $found['severity']);
    }

    /** With no peer group there is no median, and no peer finding is invented from one row. */
    public function test_no_peer_finding_without_a_peer_group(): void
    {
        $odd = $this->creative(['name' => 'Alone']);
        $this->now($odd, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 6000, 'conversions' => 6, 'revenue' => 540]);

        $peerBased = array_filter($this->insights(), static fn (array $i): bool => $i['comparison'] === 'peers');

        $this->assertSame([], array_values($peerBased));
    }

    // ---- fatigue and platforms ----------------------------------------------------------------

    /** A fatigued creative becomes an insight only while money is still going into it. */
    public function test_fatigue_is_an_insight_only_while_it_is_still_spending(): void
    {
        $spending = $this->creative(['name' => 'Still burning']);
        $stopped = $this->creative(['name' => 'Stopped']);

        foreach ([$spending, $stopped] as $creative) {
            for ($d = 40; $d >= 31; $d--) {
                $this->day($creative, now()->subDays($d)->toDateString(), [
                    'spend' => 300, 'impressions' => 40000, 'clicks' => 1200, 'frequency' => 1.4,
                ]);
            }
        }

        // Ten active days in the window, with click-through collapsing and frequency climbing.
        for ($d = 12; $d >= 3; $d--) {
            $this->day($spending, now()->subDays($d)->toDateString(), [
                'spend' => 300, 'impressions' => 40000, 'clicks' => 400, 'frequency' => 4.6,
            ]);
            $this->day($stopped, now()->subDays($d)->toDateString(), [
                'spend' => 0, 'impressions' => 40000, 'clicks' => 400, 'frequency' => 4.6,
            ]);
        }

        $fatigue = collect($this->insights())->where('key', 'creative_fatigue');

        $this->assertSame(['Still burning'], $fatigue->pluck('creative_name')->values()->all());
        $this->assertNotEmpty($fatigue->first()['fatigue_signals'], 'the evidence behind the verdict travels with it');
    }

    /**
     * «Move budget to TikTok» is only allowed about ONE asset that ran on both.
     *
     * Two different creatives on two platforms differ in more than the platform, so a finding built
     * on them would be crediting the placement for the content — and the client would move a budget
     * on it.
     */
    public function test_the_platform_recommendation_needs_one_asset_on_two_platforms(): void
    {
        $ungroupedA = $this->creative(['name' => 'Loose A', 'provider' => 'meta']);
        $ungroupedB = $this->creative(['name' => 'Loose B', 'provider' => 'tiktok']);
        $this->now($ungroupedA, ['spend' => 500, 'impressions' => 50000, 'clicks' => 500, 'conversions' => 10, 'revenue' => 1000]);
        $this->now($ungroupedB, ['spend' => 500, 'impressions' => 50000, 'clicks' => 500, 'conversions' => 40, 'revenue' => 4000]);

        $this->assertNotContains('cross_platform_opportunity', $this->keys($this->insights()));

        $group = CreativeGroup::create([
            'project_id' => $this->project->getKey(), 'name' => 'One film', 'method' => 'file_hash',
        ]);
        $onMeta = $this->creative(['name' => 'One film', 'provider' => 'meta', 'creative_group_id' => $group->getKey()]);
        $onTikTok = $this->creative(['name' => 'One film', 'provider' => 'tiktok', 'creative_group_id' => $group->getKey()]);
        $this->now($onMeta, ['spend' => 500, 'impressions' => 50000, 'clicks' => 500, 'conversions' => 10, 'revenue' => 1000]);
        $this->now($onTikTok, ['spend' => 500, 'impressions' => 50000, 'clicks' => 500, 'conversions' => 40, 'revenue' => 4000]);

        $found = collect($this->insights())->firstWhere('key', 'cross_platform_opportunity');

        $this->assertNotNull($found);
        $this->assertSame('tiktok', $found['provider']);
        $this->assertStringContainsString('tiktok', $found['detail_en']);
        $this->assertStringContainsString('meta', $found['detail_en']);
        $this->assertCount(2, $found['platforms']);
    }

    // ---- provenance ---------------------------------------------------------------------------

    /** Nothing here claims to be written by a model, and the field that would say so is present. */
    public function test_every_insight_declares_that_no_model_wrote_it(): void
    {
        $creative = $this->creative();
        $this->before($creative, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 3000]);
        $this->now($creative, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 2000]);

        $items = $this->insights();

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame('rules', $item['generated_by']);
            $this->assertFalse($item['needs_human_review']);
        }
    }

    /** An account with nothing to say gets nothing said — not a filled page of filler. */
    public function test_a_quiet_account_gets_no_recommendations(): void
    {
        $creative = $this->creative();
        $this->before($creative, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 2000, 'conversions' => 100, 'revenue' => 9000]);
        $this->now($creative, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 2000, 'conversions' => 100, 'revenue' => 9000]);

        $this->assertSame([], $this->insights());
    }

    /**
     * A finding's id is unique; its `key` is the RULE and is deliberately not.
     *
     * Found live on the dashboard: `spend_without_evidence` fires once per thin creative, so a list
     * spanning an account held twelve items and several shared that key. React de-duplicates by key,
     * so the panel rendered nine while the honest total beside it still said «12 of 91» — findings
     * silently dropped by a list that looked complete.
     */
    public function test_findings_are_uniquely_identified_even_when_one_rule_fires_many_times(): void
    {
        // Three creatives, each carrying spend behind almost no delivery — one rule, three findings.
        foreach (['a', 'b', 'c'] as $slug) {
            $creative = $this->creative(['name' => 'Thin '.$slug]);
            $this->now($creative, ['spend' => 500, 'impressions' => 40]);
        }

        $items = $this->insights();
        $keys = array_column($items, 'key');
        $ids = array_column($items, 'id');

        $this->assertGreaterThanOrEqual(3, count($items));
        // The rule repeats — that is correct, and it is why the id has to be something else.
        $this->assertLessThan(count($keys), count(array_unique($keys)));
        $this->assertSame(count($ids), count(array_unique($ids)), 'two findings share one id');
    }
}
