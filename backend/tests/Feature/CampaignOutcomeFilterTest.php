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
 * CAMPAIGN-OUTCOME-DIMENSION-001 — narrowing by the action a campaign bought.
 *
 * The objective filter answers «show me the lead work». It cannot answer the question a media buyer
 * actually asks next: «show me what collected forms», as distinct from what opened conversations.
 * Those are two campaigns in the same family whose costs are not comparable, and a product with only
 * the objective axis cannot separate them at all.
 *
 * ## The two failure modes this pins
 *
 * A filter that matches nothing must return NOTHING, not everything — an empty `whereIn` is answered
 * by Postgres with every row, which is the shape of leak an unmatched filter must never take. And an
 * axis the query could not apply must be REPORTED as unapplied rather than silently ignored, because
 * a panel that quietly answers a wider question is the defect `filter_scope` was built to surface.
 */
final class CampaignOutcomeFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Tenant $tenant;

    private Project $project;

    private ?ExternalAccount $account = null;

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

    /** @return array<string,mixed> */
    private function outcomeOf(string $campaignName, array $query = []): array
    {
        $rows = $this->fetchData('campaigns', $query);
        $row = collect($rows)->firstWhere('campaign_name', $campaignName);

        return is_array($row) ? $row : [];
    }

    /**
     * The campaign row says what it bought, beside what it was for.
     *
     * `sales` names its own action, so no provider payload is needed to settle it.
     */
    public function test_a_campaign_row_carries_the_action_it_bought(): void
    {
        $sales = $this->campaign('Eid', 'sales');
        $this->external($sales, 'sales');
        $this->metric($sales, 'meta', 'SAR');

        $row = $this->outcomeOf('Eid');

        $this->assertSame('purchase', $row['outcome']);
        $this->assertSame('تكلفة الطلب', $row['outcome_cost_label']['ar']);
    }

    /**
     * A lead campaign whose destination the provider sent is separated from one that opened chats.
     *
     * Both are `leads`. Both report «cost per result». The filter is the only thing that lets an
     * operator look at one of them.
     */
    public function test_the_filter_separates_two_campaigns_in_one_objective(): void
    {
        $form = $this->campaign('Forms', 'leads');
        $this->external($form, 'leads', ['destination_type' => 'ON_AD']);
        $this->metric($form, 'meta', 'SAR');

        $chat = $this->campaign('Chats', 'leads');
        $this->external($chat, 'leads', ['destination_type' => 'WHATSAPP']);
        $this->metric($chat, 'meta', 'SAR');

        $names = array_column($this->fetchData('campaigns', ['outcome' => 'native_lead_form']), 'campaign_name');

        $this->assertSame(['Forms'], $names, 'the chat campaign was ranked alongside the form campaign');
    }

    /**
     * **Fail closed.** A filter nothing matches empties the list rather than widening it.
     *
     * The dangerous version of this narrowing shows a reader EVERY campaign under a chip that says
     * «phone calls». The builder renders an empty match as `where 0 = 1`, so it fails closed on its
     * own — this test is what keeps that true if the narrowing is ever rewritten by hand.
     */
    public function test_an_action_no_campaign_bought_returns_nothing(): void
    {
        $sales = $this->campaign('Eid', 'sales');
        $this->external($sales, 'sales');
        $this->metric($sales, 'meta', 'SAR');

        $this->assertSame([], $this->fetchData('campaigns', ['outcome' => 'phone_call']));
    }

    /** A value outside the enum is dropped rather than failing the whole request closed. */
    public function test_an_unrecognised_action_is_ignored_rather_than_emptying_the_list(): void
    {
        $sales = $this->campaign('Eid', 'sales');
        $this->external($sales, 'sales');
        $this->metric($sales, 'meta', 'SAR');

        // Compared against the UNFILTERED list rather than a literal, so the assertion is «this
        // filter changed nothing» rather than a restatement of the fixture.
        $unfiltered = array_column($this->fetchData('campaigns'), 'campaign_name');
        $filtered = array_column($this->fetchData('campaigns', ['outcome' => 'not_a_real_action']), 'campaign_name');

        $this->assertSame($unfiltered, $filtered);
        $this->assertContains('Eid', $filtered);
    }

    /**
     * The ad-set grain declines the axis and says so.
     *
     * The action is resolved from the provider payload rather than stored, so the entity query
     * cannot narrow on it. Reporting it as applied would be the dishonesty `filter_scope` exists to
     * prevent.
     */
    public function test_the_entity_grain_declares_the_axis_it_cannot_apply(): void
    {
        $body = $this->fetchBody('entities/ad_set', ['outcome' => 'purchase']);

        $this->assertContains('outcome', $body['data']['filter_scope']['unapplied']);
        $this->assertNotContains('outcome', $body['data']['filter_scope']['applied']);
    }

    /**
     * The external campaign that carries the provider's own payload.
     *
     * @param  array<string,mixed>  $raw
     */
    private function external(UnifiedCampaign $campaign, string $objective, array $raw = []): void
    {
        /*
         * `external_account_id` is NOT NULL and has a foreign key: a campaign always belongs to an
         * account, and the schema refuses a detached one rather than storing an orphan. One account
         * is made lazily and shared by every campaign in this class.
         */
        $this->account ??= ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => app(TokenVault::class)->open(
                tenantId: (string) $this->tenant->id,
                provider: 'meta',
                tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
                connectionName: 'meta',
            )->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act-'.uniqid(),
            'name' => 'Account',
            'status' => 'active',
            'discovered_at' => now(),
        ]);

        ExternalCampaign::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(),
            'unified_campaign_id' => $campaign->getKey(),
            'provider' => 'meta',
            'external_id' => 'c-'.uniqid(),
            'name' => $campaign->name,
            'status' => 'active',
            'objective' => $objective,
            'raw' => $raw,
        ]);
    }

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
