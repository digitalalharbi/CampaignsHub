<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UX-MULTISELECT-SCALE-001 / REPORT-SCOPE-SELECTION-001 — finding the ad set that did not fit.
 *
 * ## The gap
 *
 * The builder's lists are bounded and the bound is honestly STATED — `truncated.ad_sets` is a fact
 * the response carries so the picker can say «there are more» rather than letting an operator read a
 * short list as a complete one. That was the previous unit and it is right as far as it goes.
 *
 * What it does not do is let anybody reach the ones past the cap. The picker's search box filters
 * what it was SENT, so on a project with five hundred ad sets, ad set number four hundred cannot be
 * selected at all — by any route. An operator meets that as «my ad set is not in the system», and
 * the report they build then quietly omits it. The matrix has carried «ad/ad-set selection has no
 * server-side filtering» as an open gap since the row was written.
 *
 * ## The shape
 *
 * `?axis=ad_sets&q=…` returns that ONE axis, filtered where the rows live. One axis rather than all
 * of them because a search is a question about the list being searched: re-sending nine other lists
 * on every keystroke would be slower than the problem, and re-filtering them by a term typed into a
 * different control would be wrong.
 *
 * The truncation flag is recomputed against the FILTERED set, so «there are more» keeps meaning
 * «more that match», which is what a person searching needs to know.
 */
final class ScopeOptionSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private UnifiedCampaign $campaign;

    private ExternalCampaign $external;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client->id,
            'name' => 'Project 1',
            'status' => 'active',
        ]);

        $this->operator = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator->assignRole($role);
        $this->grantMembership($this->operator, $this->tenant, Portal::App);

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'meta',
            'external_id' => 'c-1',
            'name' => 'Campaign',
            'status' => 'active',
            'objective' => 'sales',
        ]);

        $this->external = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'unified_campaign_id' => $this->campaign->id,
            'external_account_id' => $this->externalAccount()->id,
            'provider' => 'meta',
            'external_id' => 'ec-1',
            'name' => 'Campaign',
            'status' => 'active',
        ]);
    }

    /** The account chain an `external_campaigns` row needs before it can exist. */
    private function externalAccount(): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = new ExternalAccount;
        $account->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act-meta',
            'name' => 'Meta',
            'status' => 'active',
            'currency' => 'SAR',
        ])->save();

        return $account;
    }

    private function adSet(string $name): ExternalAdSet
    {
        return ExternalAdSet::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'unified_campaign_id' => $this->campaign->id,
            'provider' => 'meta',
            'external_id' => 'as-'.uniqid(),
            'external_campaign_id' => $this->external->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $params */
    private function scopeOptions(array $params = []): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/reports/scope/options?".http_build_query($params))
            ->assertOk()
            ->json('data');
    }

    /** The ad set past the cap is reachable by name — the whole point. */
    public function test_it_finds_an_ad_set_that_did_not_fit_in_the_list(): void
    {
        /*
         * Five hundred and one, because the cap is five hundred. «Ramadan» sorts after «Prospecting»
         * alphabetically, which is the ordering the list uses, so it is the row that falls off — the
         * shape of the real complaint rather than an arbitrary large number.
         */
        for ($i = 1; $i <= 500; $i++) {
            $this->adSet(sprintf('Prospecting %03d', $i));
        }
        $this->adSet('Ramadan retargeting');

        $unfiltered = $this->scopeOptions();
        $this->assertTrue($unfiltered['truncated']['ad_sets'], 'the fixture must overflow the cap or this proves nothing');
        $this->assertNotContains(
            'Ramadan retargeting',
            array_column($unfiltered['ad_sets'], 'name'),
            'the fixture must put the target PAST the cap',
        );

        $found = $this->scopeOptions(['axis' => 'ad_sets', 'q' => 'ramadan']);

        $this->assertSame(['Ramadan retargeting'], array_column($found['ad_sets'], 'name'));
        $this->assertFalse($found['truncated']['ad_sets'], 'one match cannot also be «there are more»');
    }

    /** Case does not decide whether an operator can find their own ad set. */
    public function test_the_search_is_case_insensitive(): void
    {
        $this->adSet('EID Broad KSA');

        $this->assertSame(
            ['EID Broad KSA'],
            array_column($this->scopeOptions(['axis' => 'ad_sets', 'q' => 'eid broad'])['ad_sets'], 'name'),
        );
    }

    /**
     * A search asks about ONE list, and answers about that one.
     *
     * Re-filtering the other nine axes by a term typed into this control would silently empty lists
     * the operator never touched — and re-sending them all on every keystroke would be slower than
     * the problem this solves.
     */
    public function test_it_answers_for_the_axis_it_was_asked_about(): void
    {
        $this->adSet('Ramadan retargeting');

        $found = $this->scopeOptions(['axis' => 'ad_sets', 'q' => 'ramadan']);

        $this->assertArrayHasKey('ad_sets', $found);
        $this->assertArrayNotHasKey('campaigns', $found, 'a search for ad sets re-sent every other list');
        $this->assertArrayNotHasKey('creatives', $found);
    }

    /** Every searchable axis answers, so the picker needs no special case per control. */
    public function test_every_named_axis_can_be_searched(): void
    {
        foreach (['campaigns', 'ad_sets', 'ads', 'creatives'] as $axis) {
            $data = $this->scopeOptions(['axis' => $axis, 'q' => 'zzz-nothing']);

            $this->assertSame([], $data[$axis], "«{$axis}» did not answer a search");
        }
    }

    /** An axis nobody can search says so, rather than silently returning everything. */
    public function test_an_unknown_axis_is_refused(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/reports/scope/options?axis=nonsense&q=x")
            ->assertStatus(422);
    }

    /**
     * REPORT-SCOPE-SELECTION-001 — a campaign that ran in the WINDOW, whatever its status is today.
     *
     * «Reportability = campaign lifecycle + selected period + canonical status — NOT a simplistic
     * `status === active` frontend filter», because a campaign inactive today may have been active
     * during a historical report window. A builder that grouped by today's status would file a
     * completed campaign under «did not run» and silently drop its spend from a report about the
     * month it ran in.
     */
    public function test_a_completed_campaign_that_ran_in_the_window_reports_its_last_active_day(): void
    {
        $this->campaign->forceFill(['status' => 'completed'])->save();

        DailyMetric::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'unified_campaign_id' => $this->campaign->id,
            'external_account_id' => $this->external->external_account_id,
            'external_campaign_id' => $this->external->id,
            'provider' => 'meta',
            'metric_key' => 'spend',
            'metric_date' => '2026-07-15',
            'value' => 120,
        ]);

        $row = collect($this->scopeOptions(['from' => '2026-07-01', 'to' => '2026-07-31'])['campaigns'])
            ->firstWhere('id', (string) $this->campaign->id);

        $this->assertSame('completed', $row['status']);
        $this->assertSame('2026-07-15', $row['last_active_on'], 'a campaign that ran was filed as one that did not');
    }

    /** A day of zeros is not a day the campaign ran — the same rule the relevance ordering uses. */
    public function test_a_window_of_zeros_is_not_activity(): void
    {
        DailyMetric::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'unified_campaign_id' => $this->campaign->id,
            'external_account_id' => $this->external->external_account_id,
            'external_campaign_id' => $this->external->id,
            'provider' => 'meta',
            'metric_key' => 'spend',
            'metric_date' => '2026-07-15',
            'value' => 0,
        ]);

        $row = collect($this->scopeOptions(['from' => '2026-07-01', 'to' => '2026-07-31'])['campaigns'])
            ->firstWhere('id', (string) $this->campaign->id);

        $this->assertNull($row['last_active_on'], 'a month of zeros was read as a month of running');
    }

    /**
     * And activity OUTSIDE the window is not activity inside it.
     *
     * The whole clause is that the question is asked about the period being reported on. A campaign
     * that ran in June and was dark in July must not be grouped as having run in a July report.
     */
    public function test_activity_outside_the_window_does_not_count(): void
    {
        DailyMetric::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'unified_campaign_id' => $this->campaign->id,
            'external_account_id' => $this->external->external_account_id,
            'external_campaign_id' => $this->external->id,
            'provider' => 'meta',
            'metric_key' => 'spend',
            'metric_date' => '2026-06-15',
            'value' => 500,
        ]);

        $row = collect($this->scopeOptions(['from' => '2026-07-01', 'to' => '2026-07-31'])['campaigns'])
            ->firstWhere('id', (string) $this->campaign->id);

        $this->assertNull($row['last_active_on']);
    }

    /** With no period asked about, no claim is made either way. */
    public function test_no_period_means_no_claim(): void
    {
        $row = collect($this->scopeOptions()['campaigns'])->firstWhere('id', (string) $this->campaign->id);

        $this->assertNull($row['last_active_on']);
    }

    /** And with no axis asked for, the endpoint answers exactly as it always did. */
    public function test_the_full_payload_is_unchanged_when_nothing_is_searched(): void
    {
        $this->adSet('Ramadan retargeting');

        $data = $this->scopeOptions();

        foreach (['campaigns', 'providers', 'accounts', 'ad_sets', 'ads', 'creatives', 'objectives', 'paths', 'metrics', 'truncated', 'grain'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }
}
