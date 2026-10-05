<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Services\CreativeFormatIntelligence;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CREATIVE-FORMAT-INTELLIGENCE-001 — «image or video, HERE?», with a scope and an evidence base.
 *
 * The question only means something about one advertiser, in one period, doing one job. Asked of a
 * project it blends an agency's four ad accounts into a single verdict; asked of a month holding a
 * brand campaign and a sales campaign it divides one objective's money by another's events.
 *
 * These hold the four properties that make the answer usable rather than merely available: the scope
 * is obeyed, the ad account is an axis rather than a blend, incompatible objectives are SPLIT instead
 * of ranked together, and the evidence base is stated so a spend share is never read as a verdict.
 */
final class CreativeFormatIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ClientWorkspace $workspace;

    private ExternalAccount $meta;

    private ExternalAccount $snap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'cfi-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $this->workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->workspace->getKey(), 'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->meta = $this->account('meta', 'Meta Account A');
        $this->snap = $this->account('snapchat', 'Snapchat Account B');
        $this->bind($this->meta, true);
        $this->bind($this->snap, true);
    }

    /**
     * Two formats on ONE account, compared on that account's own objective.
     *
     * The baseline: a sales account where video and image both ran enough to speak for themselves.
     */
    public function test_image_and_video_are_compared_within_one_account(): void
    {
        $campaign = $this->campaign('sales', 'Sales');

        $this->creatives($this->meta, $campaign, 'video', 3, ['spend' => 300.0, 'conversions' => 30.0]);
        $this->creatives($this->meta, $campaign, 'image', 3, ['spend' => 300.0, 'conversions' => 10.0]);

        $answer = $this->ask($this->meta->getKey());

        $this->assertCount(1, $answer['objectives'], 'one objective ran, so there is one comparison');
        $comparison = $answer['objectives'][0]['comparison'];

        $this->assertNull($comparison['refusal']);
        $this->assertSame('video', $comparison['best'], 'video produced three times the orders for the same spend');
        $this->assertSame(['video', 'image'], array_column($comparison['formats'], 'format'));
    }

    /**
     * ACCOUNT-SCOPE-ISOLATION-001 — the second account does not leak into the first.
     *
     * Two accounts of DIFFERENT providers here, and one of one provider in the next test: neither
     * closeness is a reason to blend two advertisers' media into one verdict.
     */
    public function test_another_accounts_creatives_never_enter_this_accounts_answer(): void
    {
        $campaign = $this->campaign('sales', 'Sales');

        $this->creatives($this->meta, $campaign, 'video', 3, ['spend' => 300.0, 'conversions' => 30.0]);
        $this->creatives($this->meta, $campaign, 'image', 3, ['spend' => 300.0, 'conversions' => 10.0]);
        $this->creatives($this->snap, $campaign, 'image', 9, ['spend' => 900.0, 'conversions' => 900.0]);

        $answer = $this->ask($this->meta->getKey());

        $this->assertSame([['id' => (string) $this->meta->getKey(), 'name' => 'Meta Account A', 'provider' => 'meta']], $answer['accounts']);
        $this->assertSame(
            ['video' => 3, 'image' => 3],
            $this->creativeCounts($answer['objectives'][0]['comparison']),
            "the other account's nine images were counted into this account's comparison",
        );
    }

    /** A project rollup may span accounts — and it still names them, so the answer can be drilled into. */
    public function test_a_project_rollup_names_every_account_it_blends(): void
    {
        $campaign = $this->campaign('sales', 'Sales');

        $this->creatives($this->meta, $campaign, 'video', 3, ['spend' => 300.0, 'conversions' => 30.0]);
        $this->creatives($this->snap, $campaign, 'image', 3, ['spend' => 300.0, 'conversions' => 10.0]);

        $answer = $this->ask(null);

        $this->assertSame(
            ['Meta Account A', 'Snapchat Account B'],
            array_column($answer['accounts'], 'name'),
        );
    }

    /** A DESELECTED account is not this project's to compare — DISCOVERED ≠ SELECTED. */
    public function test_a_deselected_accounts_creatives_are_not_compared(): void
    {
        $retired = $this->account('meta', 'Retired Account');
        $this->bind($retired, false);

        $campaign = $this->campaign('sales', 'Sales');
        $this->creatives($this->meta, $campaign, 'video', 3, ['spend' => 300.0, 'conversions' => 30.0]);
        $this->creatives($retired, $campaign, 'image', 4, ['spend' => 400.0, 'conversions' => 400.0]);

        $answer = $this->ask(null);

        $this->assertSame(['Meta Account A'], array_column($answer['accounts'], 'name'));
        $this->assertArrayNotHasKey('image', $answer['coverage'], "a deselected account's images were counted");
    }

    /**
     * Incompatible jobs are SPLIT, never ranked together.
     *
     * A sales video against a traffic image on one blended metric is the comparison with two units
     * this whole module exists to refuse.
     */
    public function test_mixed_objectives_produce_one_comparison_each(): void
    {
        $sales = $this->campaign('sales', 'Sales');
        $traffic = $this->campaign('traffic', 'Traffic');

        $this->creatives($this->meta, $sales, 'video', 3, ['spend' => 300.0, 'conversions' => 30.0]);
        $this->creatives($this->meta, $sales, 'image', 3, ['spend' => 300.0, 'conversions' => 10.0]);
        $this->creatives($this->meta, $traffic, 'video', 3, ['spend' => 300.0, 'clicks' => 100.0, 'impressions' => 10000.0]);
        $this->creatives($this->meta, $traffic, 'image', 3, ['spend' => 300.0, 'clicks' => 900.0, 'impressions' => 10000.0]);

        $answer = $this->ask($this->meta->getKey());

        $families = array_column($answer['objectives'], 'family');
        sort($families);

        $this->assertSame(['sales', 'traffic'], $families, 'two jobs ran and produced one verdict');

        $byFamily = collect($answer['objectives'])->keyBy('family');

        $this->assertSame('video', $byFamily['sales']['comparison']['best']);
        $this->assertSame('image', $byFamily['traffic']['comparison']['best'], 'the traffic cut was decided by the sales cut');
    }

    /** An awareness comparison is never settled on a sales figure. */
    public function test_an_awareness_comparison_is_not_decided_on_roas(): void
    {
        $brand = $this->campaign('brand_awareness', 'Brand');

        $this->creatives($this->meta, $brand, 'video', 3, ['spend' => 300.0, 'impressions' => 100000.0]);
        $this->creatives($this->meta, $brand, 'image', 3, ['spend' => 300.0, 'impressions' => 50000.0]);

        $comparison = $this->ask($this->meta->getKey())['objectives'][0]['comparison'];

        $this->assertNotSame('roas', $comparison['metric']);
        $this->assertContains($comparison['metric'], ['cpm', 'ctr'], 'awareness was judged on something it was not bought for');
    }

    /**
     * The evidence base is STATED, so a spend share is never read as a verdict.
     *
     * «90% of spend on video» means nothing about video until the reader knows how many creatives
     * stood behind each format and how many of them reported at all.
     */
    public function test_creatives_without_metrics_are_counted_separately(): void
    {
        $campaign = $this->campaign('sales', 'Sales');

        $this->creatives($this->meta, $campaign, 'video', 3, ['spend' => 300.0, 'conversions' => 30.0]);
        $this->creatives($this->meta, $campaign, 'video', 2, null);
        $this->creatives($this->meta, $campaign, 'image', 2, ['spend' => 200.0, 'conversions' => 4.0]);

        $coverage = $this->ask($this->meta->getKey())['coverage'];

        $this->assertSame(['creatives' => 5, 'with_metrics' => 3, 'without_metrics' => 2], $coverage['video']);
        $this->assertSame(['creatives' => 2, 'with_metrics' => 2, 'without_metrics' => 0], $coverage['image']);
    }

    /** One asset is not a format, and it is held out rather than crowned. */
    public function test_a_single_creative_cannot_speak_for_its_format(): void
    {
        $campaign = $this->campaign('sales', 'Sales');

        $this->creatives($this->meta, $campaign, 'video', 1, ['spend' => 100.0, 'conversions' => 90.0]);
        $this->creatives($this->meta, $campaign, 'image', 3, ['spend' => 300.0, 'conversions' => 9.0]);

        $objective = $this->ask($this->meta->getKey())['objectives'][0];

        $this->assertSame('only_one_format_ran_enough_to_compare', $objective['comparison']['refusal']);
        $this->assertSame('insufficient', $objective['evidence']);
        $this->assertSame(
            [['format' => 'video', 'creatives' => 1]],
            $objective['comparison']['too_few_to_speak_for_their_format'],
        );
    }

    /** Spend mix covers EVERY group, not just the two being ranked — and never files unknown as image. */
    public function test_the_spend_mix_keeps_every_format_including_unlabelled(): void
    {
        $campaign = $this->campaign('sales', 'Sales');

        // Per CREATIVE, so the groups total 600 / 200 / 100 / 100 — the amounts asserted below.
        $this->creatives($this->meta, $campaign, 'video', 2, ['spend' => 300.0, 'conversions' => 10.0]);
        $this->creatives($this->meta, $campaign, 'image', 2, ['spend' => 100.0, 'conversions' => 10.0]);
        $this->creatives($this->meta, $campaign, 'carousel', 1, ['spend' => 100.0, 'conversions' => 1.0]);
        $this->creatives($this->meta, $campaign, null, 1, ['spend' => 100.0, 'conversions' => 1.0]);

        $mix = $this->ask($this->meta->getKey())['spend_mix'];

        $this->assertTrue($mix['complete']);
        $this->assertEqualsWithDelta(1000.0, $mix['total'], 0.01);

        $byFormat = collect($mix['formats'])->keyBy('format');

        $this->assertEqualsWithDelta(0.6, $byFormat['video']['share'], 0.001);
        $this->assertEqualsWithDelta(0.2, $byFormat['image']['share'], 0.001);
        $this->assertArrayHasKey('carousel', $byFormat->all());
        $this->assertArrayHasKey('unlabelled', $byFormat->all(), 'an unknown format was filed under something');
    }

    /**
     * A withheld spend is not a zero, and a share over an incomplete denominator is not a share.
     *
     * FX-001: a creative whose spend could not be converted keeps its original beside a null value.
     * Summing the convertible subset and calling it 100% is the collapse the money contract refuses
     * everywhere else.
     */
    public function test_a_withheld_spend_suppresses_the_share_rather_than_understating_it(): void
    {
        $campaign = $this->campaign('sales', 'Sales');

        $this->creatives($this->meta, $campaign, 'video', 2, ['spend' => 600.0, 'conversions' => 10.0]);
        $this->creatives($this->meta, $campaign, 'image', 2, ['spend' => null, 'spend_original' => 200.0, 'conversions' => 10.0]);

        $mix = $this->ask($this->meta->getKey())['spend_mix'];
        $byFormat = collect($mix['formats'])->keyBy('format');

        $this->assertFalse($mix['complete']);
        $this->assertNull($mix['total']);
        $this->assertNull($byFormat['image']['spend'], 'a withheld amount was summed as zero');
        $this->assertNull($byFormat['image']['share']);
    }

    /** Evidence rises with the count and never claims a significance this product does not compute. */
    public function test_evidence_is_derived_from_the_counts(): void
    {
        $campaign = $this->campaign('sales', 'Sales');

        $this->creatives($this->meta, $campaign, 'video', 2, ['spend' => 200.0, 'conversions' => 20.0]);
        $this->creatives($this->meta, $campaign, 'image', 2, ['spend' => 200.0, 'conversions' => 5.0]);

        $this->assertSame('moderate', $this->ask($this->meta->getKey())['objectives'][0]['evidence']);

        $this->creatives($this->meta, $campaign, 'video', 4, ['spend' => 400.0, 'conversions' => 40.0]);
        $this->creatives($this->meta, $campaign, 'image', 4, ['spend' => 400.0, 'conversions' => 10.0]);

        $this->assertSame('high', $this->ask($this->meta->getKey())['objectives'][0]['evidence']);
    }

    /**
     * The endpoint answers the same thing the service does, for the same scope.
     *
     * SAME NUMBERS EVERYWHERE: a surface that computed its own would be a second answer about one
     * advertiser, and the first time the two disagreed nobody would know which to believe. The route
     * is the service and nothing else.
     */
    public function test_the_endpoint_returns_the_same_answer_as_the_service(): void
    {
        $campaign = $this->campaign('sales', 'Sales');
        $this->creatives($this->meta, $campaign, 'video', 3, ['spend' => 300.0, 'conversions' => 30.0]);
        $this->creatives($this->meta, $campaign, 'image', 3, ['spend' => 300.0, 'conversions' => 10.0]);

        $operator = $this->operator();

        $served = $this->actingAs($operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/creatives/format-intelligence"
                ."?from=2026-09-01&to=2026-09-30&external_account_id={$this->meta->getKey()}")
            ->assertOk()
            ->json('data');

        /*
         * `assertEquals`, not `assertSame`: JSON renders 900.0 as 900, so an identity check would
         * fail on the transport rather than on the answer. The claim is that the FIGURES match.
         */
        $this->assertEquals($this->ask($this->meta->getKey()), $served);
    }

    /** And it is not readable by somebody who may not see campaigns. */
    public function test_the_endpoint_requires_campaign_visibility(): void
    {
        $this->getJson("/api/v1/projects/{$this->project->getKey()}/creatives/format-intelligence")
            ->assertUnauthorized();
    }

    private function operator(): User
    {
        $this->seed(PermissionSeeder::class);

        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $role = Role::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid(),
        ]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $user = User::create([
            'name' => 'Op', 'email' => 'cfi-'.uniqid().'@a.test', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function ask(mixed $accountId): array
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        return app(CreativeFormatIntelligence::class)->forScope(
            (string) $this->project->getKey(),
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
            $accountId === null ? null : (string) $accountId,
        );
    }

    /** @param array<string, mixed> $comparison @return array<string, int> */
    private function creativeCounts(array $comparison): array
    {
        $out = [];

        foreach ($comparison['formats'] ?? [] as $row) {
            $out[$row['format']] = (int) $row['creatives'];
        }

        return $out;
    }

    private function account(string $provider, string $name): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(), provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'a-'.uniqid(),
            'name' => $name, 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    private function bind(ExternalAccount $account, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(), 'external_account_id' => $account->getKey(),
            'provider' => $account->provider, 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }

    private function campaign(string $objective, string $name): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => $name.'-'.uniqid(), 'status' => 'active', 'objective' => $objective,
        ]);
    }

    /** @param array<string, float|null>|null $metrics */
    private function creatives(ExternalAccount $account, UnifiedCampaign $campaign, ?string $format, int $count, ?array $metrics): void
    {
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(), 'unified_campaign_id' => $campaign->getKey(),
            'provider' => $account->provider, 'external_id' => 'ec-'.uniqid(), 'name' => 'C', 'status' => 'active',
        ]);

        for ($i = 0; $i < $count; $i++) {
            $creative = ExternalCreative::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                'campaign_id' => $campaign->getKey(), 'external_campaign_id' => $external->getKey(),
                'external_account_id' => $account->getKey(), 'provider' => $account->provider,
                'external_id' => 'cr-'.uniqid().$i, 'external_creative_id' => 'xc-'.uniqid().$i, 'format' => $format, 'name' => 'Creative '.$i,
            ]);

            if ($metrics === null) {
                continue;
            }

            DB::table('creative_daily_metrics')->insert(array_merge([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'creative_id' => $creative->getKey(),
                'campaign_id' => $campaign->getKey(),
                'metric_date' => '2026-09-15',
                'created_at' => now(),
                'updated_at' => now(),
            ], $metrics));
        }
    }
}
