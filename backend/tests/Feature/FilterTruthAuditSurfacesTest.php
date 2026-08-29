<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ANALYTICS-FILTER-TRUTH-001 on the three panels that were being SENT a filter and ignoring it.
 *
 * The client sends `provider`, `objective` and `campaign` to every metrics endpoint. Normalization,
 * freshness and attribution read only the provider. Nothing failed, nothing warned: the request
 * carried the campaign, the response was shaped like a filtered response, and the panel sat under
 * chips naming one campaign while answering for the whole project.
 *
 * That is worse than a frontend-only filter rather than milder. A React-only filter at least shows
 * the reader a list that changed; this showed them an unchanged panel that looked deliberate.
 *
 * Two different corrections, and which one applies is a question about the data, not about effort:
 *
 *   - **Normalization** audits `daily_metrics` row by row. Every one of the three axes bounds those
 *     rows exactly, so all three now narrow it — through the aggregator's own predicate, not a
 *     second copy of one clause of it.
 *   - **Freshness** and **attribution** cannot narrow by campaign without lying. Source health is a
 *     property of a connection, and the store half of an attribution reconciliation has no campaign
 *     on it at all — narrowing the platform half alone would manufacture a gap out of the filter and
 *     present it as an attribution finding. They decline the axis and SAY they declined it.
 *
 * The declaration is the part that must not rot: a panel silently answering a wider question is
 * exactly the defect, so «which axes did you actually apply» is now in the response.
 */
final class FilterTruthAuditSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $sales;

    private UnifiedCampaign $awareness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'ft-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'ft-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $this->sales = $this->campaign('Ramadan Sales', 'sales');
        $this->awareness = $this->campaign('Brand Awareness', 'awareness');

        /*
         * Two campaigns, two currencies, two providers. The currencies are what makes the narrowing
         * VISIBLE: a normalization audit filtered to the sales campaign must stop reporting the
         * awareness campaign's currency, and «did the filter do anything» has an unambiguous answer.
         */
        $this->metric($this->sales, 'meta', 'USD');
        $this->metric($this->awareness, 'google', 'AED');

        /*
         * Orders on BOTH campaigns, on the same provider.
         *
         * The attribution assertion below is about a number, not about two empty lists being equal:
         * with only spend rows the platform-reported side is empty and «filtered equals unfiltered»
         * holds for the wrong reason. Both campaigns report through meta so a campaign filter, if it
         * were half-applied, would visibly halve the platform side.
         */
        $this->conversions($this->sales, 'meta', 7);
        $this->conversions($this->awareness, 'meta', 5);

        app(TenantContext::class)->forget();
    }

    // ---- normalization: narrows by all three axes -------------------------------------------------

    public function test_normalization_narrows_by_campaign(): void
    {
        $all = $this->fetchData('normalization');
        $this->assertSame(['AED', 'USD'], $this->currencies($all), 'both campaigns before filtering');

        $filtered = $this->fetchData('normalization', ['campaign' => $this->sales->getKey()]);

        $this->assertSame(['USD'], $this->currencies($filtered));
    }

    public function test_normalization_narrows_by_objective(): void
    {
        $filtered = $this->fetchData('normalization', ['objective' => 'awareness']);

        $this->assertSame(['AED'], $this->currencies($filtered));
    }

    /**
     * The axes compose. Two filters that each match, applied together, must still match — an `AND`
     * silently implemented as an `OR` widens the answer while every single-axis test still passes.
     */
    public function test_normalization_composes_the_axes(): void
    {
        $this->assertSame(
            ['USD'],
            $this->currencies($this->fetchData('normalization', [
                'campaign' => $this->sales->getKey(),
                'provider' => 'meta',
            ])),
        );

        /* The same campaign, asked for under the other provider, is not a wider match — it is none. */
        $this->assertSame(
            [],
            $this->currencies($this->fetchData('normalization', [
                'campaign' => $this->sales->getKey(),
                'provider' => 'google',
            ])),
        );
    }

    public function test_normalization_declares_every_axis_applied(): void
    {
        $scope = $this->fetchData('normalization', [
            'campaign' => $this->sales->getKey(),
            'objective' => 'sales',
            'provider' => 'meta',
        ])['filter_scope'];

        $this->assertSame(['provider', 'objective', 'campaign'], $scope['applied']);
        $this->assertSame([], $scope['unapplied']);
    }

    /** An axis nobody asked for is not reported as applied — the statement is about THIS request. */
    public function test_an_unrequested_axis_is_not_claimed(): void
    {
        $scope = $this->fetchData('normalization')['filter_scope'];

        $this->assertSame([], $scope['applied']);
        $this->assertSame([], $scope['unapplied']);
    }

    // ---- freshness and attribution: decline, and say so -------------------------------------------

    public function test_freshness_names_the_axes_it_does_not_apply(): void
    {
        $body = $this->fetchBody('freshness', [
            'campaign' => $this->sales->getKey(),
            'objective' => 'sales',
            'provider' => 'meta',
        ]);

        $scope = $body['meta']['filter_scope'];

        $this->assertSame(['provider'], $scope['applied']);
        $this->assertSame(['objective', 'campaign'], $scope['unapplied']);
    }

    public function test_attribution_names_the_axes_it_does_not_apply(): void
    {
        $scope = $this->fetchData('attribution', ['campaign' => $this->sales->getKey()])['filter_scope'];

        $this->assertSame([], $scope['applied']);
        $this->assertSame(['campaign'], $scope['unapplied']);
    }

    /**
     * The declined axis is declined COMPLETELY.
     *
     * A half-applied campaign filter is the outcome this endpoint is protected from: it would narrow
     * the platform-reported side of the reconciliation while the store ledger stayed whole, and
     * report the difference as an attribution gap. So the platform figures must be identical with
     * and without the filter.
     */
    public function test_attribution_does_not_half_apply_the_campaign_axis(): void
    {
        $filtered = $this->fetchData('attribution', ['campaign' => $this->sales->getKey()]);

        $meta = collect($filtered['platform_reported']['platforms'])
            ->firstWhere('provider', 'meta');

        /*
         * Twelve, not seven. Seven is the sales campaign alone — the number a half-applied filter
         * would produce, and the one that would be reconciled against a store ledger still holding
         * every order, turning the filter itself into a reported attribution gap.
         */
        $this->assertNotNull($meta, 'the platform side must report meta');
        $this->assertSame(12.0, (float) $meta['platform_reported_orders']);
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function campaign(string $name, string $objective): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => $name,
            'status' => 'active',
            'objective' => $objective,
        ]);
    }

    /** A money row: it carries a currency, so the normalization audit counts it. */
    private function metric(UnifiedCampaign $campaign, string $provider, string $currency): void
    {
        $this->row($campaign, $provider, 'spend', 100, $currency);
    }

    /**
     * A COUNT row, which is what the attribution reconciliation reads.
     *
     * Deliberately currency-less: a conversion count has no currency, and the normalization audit
     * skips rows whose `original_currency` is null for exactly that reason. Giving these rows a
     * currency would put the awareness campaign into the USD bucket and make the objective test
     * pass or fail on a fixture detail rather than on the filter.
     */
    private function conversions(UnifiedCampaign $campaign, string $provider, float $count): void
    {
        $this->row($campaign, $provider, 'conversions', $count, null);
    }

    private function row(UnifiedCampaign $campaign, string $provider, string $key, float $value, ?string $currency): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $campaign->getKey(),
            'provider' => $provider,
            'metric_key' => $key,
            'metric_date' => now()->subDay()->toDateString(),
            'value' => $value,
            'original_amount' => $currency === null ? null : $value,
            'original_currency' => $currency,
            'project_currency' => $currency,
            'exchange_rate' => $currency === null ? null : 1,
        ]);
    }

    /*
     * `fetchBody` / `fetchData`, not `getJson` / `get`: both of those are PUBLIC on Laravel's
     * TestCase, and a private override of a public parent method is a fatal error at class-load
     * time — the suite does not fail, it refuses to start. `CampaignOptionsTest` records the same
     * collision for `options()`.
     */
    /** @param array<string, string> $query */
    private function fetchBody(string $path, array $query = []): array
    {
        $qs = http_build_query($query + [
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ]);

        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/metrics/{$path}?{$qs}")
            ->assertOk()
            ->json();
    }

    /** @param array<string, string> $query */
    private function fetchData(string $path, array $query = []): array
    {
        return $this->fetchBody($path, $query)['data'];
    }

    /** @return list<string> the distinct original currencies the audit reports, sorted. */
    private function currencies(array $body): array
    {
        $out = array_values(array_unique(array_column($body['currencies'] ?? [], 'from')));
        sort($out);

        return $out;
    }
}
