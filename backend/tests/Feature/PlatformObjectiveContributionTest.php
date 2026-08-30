<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ObjectivePerformance;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PLATFORM-DECISION-ANALYTICS-001 — platforms inside each marketing path, never across them.
 *
 * The Platforms surface listed platforms with one set of figures over every objective at once. That
 * row cannot answer «which platform is contributing most to this objective», and the comparison it
 * invites is the one that must never be made: a platform buying awareness and a platform buying
 * sales are not better or worse than each other, and ranking them together invents a verdict out of
 * the work each was given.
 */
final class PlatformObjectiveContributionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private const FROM = '2026-08-01';

    private const TO = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'pobj-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    /** Two platforms on one path: comparable, with each one's share of THAT path's spend. */
    public function test_two_platforms_on_one_path_are_comparable_and_share_its_spend(): void
    {
        $this->spend('meta', 'awareness', 6_000);
        $this->spend('tiktok', 'awareness', 2_000);

        $awareness = $this->path('awareness');

        $this->assertTrue($awareness['comparable']);
        $this->assertSame('two_or_more_platforms_spent', $awareness['comparable_reason']);
        $this->assertSame(8_000.0, $awareness['spend']);

        $share = array_column($awareness['platforms'], 'spend_share', 'provider');
        $this->assertEqualsWithDelta(0.75, $share['meta'], 0.001);
        $this->assertEqualsWithDelta(0.25, $share['tiktok'], 0.001);
    }

    /**
     * One platform on a path is not a ranking.
     *
     * «Meta is the best platform for awareness» when Meta is the only platform that ran awareness is
     * a sentence with no evidence behind it, and it is exactly what a surface writes when the payload
     * hands it a sorted list of one.
     */
    public function test_a_path_only_one_platform_ran_is_not_comparable(): void
    {
        $this->spend('meta', 'awareness', 6_000);

        $awareness = $this->path('awareness');

        $this->assertFalse($awareness['comparable']);
        $this->assertSame('only_one_platform_spent', $awareness['comparable_reason']);
        // The row is still there — the figure is real, only the comparison is absent.
        $this->assertCount(1, $awareness['platforms']);
    }

    /** A path nobody ran says so, rather than reading as a path that performed badly. */
    public function test_a_path_nobody_ran_says_nothing_was_spent_on_it(): void
    {
        $this->spend('meta', 'awareness', 1_000);

        $conversion = $this->path('conversion');

        $this->assertFalse($conversion['comparable']);
        $this->assertSame('nothing_spent_on_this_path', $conversion['comparable_reason']);
        $this->assertSame([], $conversion['platforms']);
    }

    /**
     * The share is of the PATH, not of the project.
     *
     * «40% of awareness» is a fact about a decision somebody made. «40% of everything» is a fact
     * about the mix of work, and reading it as performance is how a platform running one big
     * conversion campaign comes to look like the best awareness buyer in the account.
     */
    public function test_the_share_is_of_the_path_not_of_the_whole_account(): void
    {
        $this->spend('meta', 'awareness', 1_000);
        $this->spend('tiktok', 'awareness', 1_000);
        $this->spend('snapchat', 'sales', 98_000);

        $share = array_column($this->path('awareness')['platforms'], 'spend_share', 'provider');

        $this->assertEqualsWithDelta(0.5, $share['meta'], 0.001);
        $this->assertEqualsWithDelta(0.5, $share['tiktok'], 0.001);
    }

    /**
     * And there is no list that contains platforms from two paths.
     *
     * The shape is the guard: a «best platform» card can only be written against a flat list, so the
     * payload never produces one, and it says why in as many words.
     */
    public function test_no_ranking_crosses_the_paths(): void
    {
        $this->spend('meta', 'awareness', 6_000);
        $this->spend('tiktok', 'sales', 6_000);

        $out = $this->build();

        $this->assertFalse($out['cross_path_comparison']);
        $this->assertStringContainsString('not compared across paths', $out['cross_path_reason_en']);

        foreach ($out['paths'] as $path) {
            foreach ($path['platforms'] as $platform) {
                $this->assertArrayNotHasKey('rank', $platform, 'a rank inside a path is fine; a rank across them is what this refuses');
            }
        }

        $awareness = array_column($out['paths'], null, 'path')['awareness'];
        $conversion = array_column($out['paths'], null, 'path')['conversion'];

        $this->assertSame(['meta'], array_column($awareness['platforms'], 'provider'));
        $this->assertSame(['tiktok'], array_column($conversion['platforms'], 'provider'));
    }

    /** The path totals the existing surface reports are unchanged by the new grain. */
    public function test_the_paths_still_total_what_they_did_before(): void
    {
        $this->spend('meta', 'awareness', 6_000);
        $this->spend('tiktok', 'awareness', 2_000);

        $paths = array_column((new ObjectivePerformance)->build(Carbon::parse(self::FROM), Carbon::parse(self::TO))['paths'], null, 'path');

        $this->assertEqualsWithDelta(8_000.0, $paths['awareness']['spend'], 0.01);
    }

    /** @return array<string,mixed> */
    private function build(): array
    {
        return (new ObjectivePerformance)->byPlatform(Carbon::parse(self::FROM), Carbon::parse(self::TO));
    }

    /** @return array<string,mixed> */
    private function path(string $path): array
    {
        return array_column($this->build()['paths'], null, 'path')[$path];
    }

    private function spend(string $provider, string $objective, float $amount): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => "{$provider} {$objective}", 'status' => 'active', 'objective' => $objective,
        ]);

        $account = $this->account($provider);

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(), 'unified_campaign_id' => $campaign->id,
            'provider' => $provider, 'external_id' => 'x-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);

        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(),
            'external_campaign_id' => $external->getKey(),
            'unified_campaign_id' => $campaign->id,
            'provider' => $provider,
            'metric_key' => 'spend',
            'metric_date' => '2026-08-10',
            'value' => $amount,
            'original_amount' => $amount,
            'original_currency' => 'SAR',
            'project_currency' => 'SAR',
            'exchange_rate' => 1,
        ]);
    }

    /** @var array<string,ExternalAccount> */
    private array $accounts = [];

    private function account(string $provider): ExternalAccount
    {
        if (isset($this->accounts[$provider])) {
            return $this->accounts[$provider];
        }

        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = new ExternalAccount;
        $account->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => 'act-'.$provider,
            'name' => $provider,
            'status' => 'active',
            'currency' => 'SAR',
        ])->save();

        return $this->accounts[$provider] = $account;
    }
}
