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
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * REPORT-SCOPE-SELECTION-001 — reportability is lifecycle AND PERIOD, never `status === active`.
 *
 * The report builder listed every campaign a project has ever had, flat and ordered by name, with a
 * status string beside it. Two things follow from that, and the second is the one that puts a wrong
 * number in front of a client:
 *
 *   1. An operator building a report for last July has to recognise, from a name, which campaigns
 *      were running last July.
 *   2. Filtering that list by `status === 'active'` — the obvious fix — is WRONG, and the
 *      requirement says so explicitly: **a campaign inactive today may have been running through the
 *      entire window being reported on.** Excluding it silently removes real spend from a client's
 *      report.
 *
 * So the builder is told, per campaign, the last day it actually reported anything INSIDE the
 * requested window. That is a fact about the period, computed the same way the campaign breakdown
 * computes it (a positive value, never a row of zeros), and it is what lets the picker group by
 * lifecycle without guessing.
 */
final class ReportScopeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Tenant $tenant;

    private ExternalAccount $account;

    private UnifiedCampaign $ranInJuly;

    private UnifiedCampaign $runningNow;

    private UnifiedCampaign $neverRan;

    private UnifiedCampaign $allZeros;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'rs-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'rs-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->account = $this->account();

        // Stopped TODAY, but it ran through the whole window being reported on.
        $this->ranInJuly = $this->campaign('Ran in July', 'completed');
        $this->sync($this->ranInJuly, 500.0, '2026-07-14');

        $this->runningNow = $this->campaign('Running now', 'active');
        $this->sync($this->runningNow, 300.0, '2026-07-30');

        // Switched on and has never spent anything.
        $this->neverRan = $this->campaign('Never ran', 'active');

        /*
         * Rows every day of the window, all of them ZERO.
         *
         * This is the case that separates «reported nothing» from «did not run», and without it the
         * positive-value filter is decoration: removing the filter changed no result until this
         * campaign existed. A campaign dark all month still has a row for every day of it.
         */
        $this->allZeros = $this->campaign('Dark all July', 'active');
        $this->sync($this->allZeros, 0.0, '2026-07-10');
        $this->sync($this->allZeros, 0.0, '2026-07-20');

        app(TenantContext::class)->forget();
    }

    /**
     * The campaign that STOPPED is still reportable for the window it ran in.
     *
     * This is the whole point. A builder that hid it would quietly drop its spend from a July report
     * — and nothing in the output would say a campaign had been left out.
     */
    public function test_a_campaign_that_has_since_stopped_is_still_reportable_for_the_window_it_ran_in(): void
    {
        $rows = collect($this->builderCampaigns('2026-07-01', '2026-07-31'))->keyBy('id');

        $this->assertSame('2026-07-14', $rows[(string) $this->ranInJuly->id]['last_active_on']);
        $this->assertSame('completed', $rows[(string) $this->ranInJuly->id]['status']);
    }

    /** A campaign that never spent has no active day — and is still listed, never hidden. */
    public function test_a_campaign_that_never_ran_is_listed_with_no_active_day(): void
    {
        $rows = collect($this->builderCampaigns('2026-07-01', '2026-07-31'))->keyBy('id');

        $this->assertArrayHasKey((string) $this->neverRan->id, $rows);
        $this->assertNull($rows[(string) $this->neverRan->id]['last_active_on']);
    }

    /**
     * A month of zeros is not a month of running.
     *
     * The campaign reported every day of the window and spent nothing on all of them. Counting those
     * rows as activity would put it in front of an operator as live — the same confusion between «no
     * data» and «zero» the money contract exists to prevent, here deciding what goes into a client's
     * report.
     */
    public function test_a_window_of_zeros_is_not_a_window_the_campaign_ran_in(): void
    {
        $rows = collect($this->builderCampaigns('2026-07-01', '2026-07-31'))->keyBy('id');

        $this->assertNull($rows[(string) $this->allZeros->id]['last_active_on']);
    }

    /**
     * The answer follows the WINDOW, not today.
     *
     * Asked about June, the July campaign has no active day — it did not run then. A picker that
     * cached one answer for all periods would tell an operator building a June report that a July
     * campaign was live.
     */
    public function test_the_active_day_is_answered_for_the_window_that_was_asked_about(): void
    {
        $june = collect($this->builderCampaigns('2026-06-01', '2026-06-30'))->keyBy('id');

        $this->assertNull($june[(string) $this->ranInJuly->id]['last_active_on']);
        $this->assertNull($june[(string) $this->runningNow->id]['last_active_on']);
    }

    /** With no window asked for, no claim is made — an absent answer, never a guessed one. */
    public function test_no_window_means_no_claim_about_activity(): void
    {
        $rows = collect($this->builderCampaigns(null, null))->keyBy('id');

        $this->assertArrayHasKey('last_active_on', $rows[(string) $this->ranInJuly->id]);
        $this->assertNull($rows[(string) $this->ranInJuly->id]['last_active_on']);
    }

    /** @return list<array<string,mixed>> */
    private function builderCampaigns(?string $from, ?string $to): array
    {
        $q = $from === null ? '' : "?from={$from}&to={$to}";

        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/reports/live/options{$q}")
            ->assertOk()
            ->json('data.campaigns');
    }

    private function campaign(string $name, string $status): UnifiedCampaign
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => $status, 'objective' => 'sales',
        ]);
    }

    private function sync(UnifiedCampaign $campaign, float $spend, string $date): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(),
            'unified_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'e-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);

        app(UpsertDailyMetrics::class)->handle([
            new NormalizedMetric(
                tenantId: (string) $this->tenant->id,
                projectId: (string) $this->project->id,
                provider: 'meta',
                externalAccountId: (string) $this->account->getKey(),
                externalCampaignId: (string) $external->id,
                unifiedCampaignId: (string) $campaign->id,
                metricDate: Carbon::parse($date),
                metricKey: 'spend',
                value: $spend,
                projectCurrency: 'SAR',
            ),
        ]);

        app(TenantContext::class)->forget();
    }

    private function account(): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'meta-ad', 'name' => 'Meta', 'currency' => 'SAR', 'status' => 'active',
        ]);
    }
}
