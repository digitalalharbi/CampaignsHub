<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-RESULT-AVAILABILITY-001 — «الطلبات 0» on an account that has never measured an order.
 *
 * ## What the owner saw
 *
 * Creative cards reading «الطلبات 0» where it is not clear the provider reported purchases for that
 * creative and that period at all. `0` and `—` are different claims — one says the ad sold nothing,
 * the other says nobody can tell — and a card that prints the first when it means the second is
 * telling a client their creative failed.
 *
 * ## Why `reported` alone cannot answer it
 *
 * The pipeline is already careful at its edge: `SnapchatConnector` skips a field the response omits
 * or sends as null, and `UpsertCreativeDailyMetrics` skips it again, so a metric nobody sent stays
 * NULL and reads as «not reported». That rule holds and is not what is being changed.
 *
 * The gap is one rung up. Snapchat is ASKED for `conversion_purchases` on every creative — it is in
 * `SnapchatConnector::METRICS`, requested for the creative grain regardless of what the campaign was
 * bought to do — and it answers `0` for an account with no purchase measurement configured at all,
 * exactly as it answers `0` for an account that measures purchases and sold none. Both arrive as a
 * non-null zero, `reported['orders']` is computed as `conversions !== null`, and the card prints a
 * measured zero for a figure the account has never been able to measure.
 *
 * ## The evidence this uses, and the evidence it refuses
 *
 * It does not guess from the shape of one number. The question asked is whether this ACCOUNT has
 * ever reported a non-zero value for the metric, at any grain, across everything we hold — which is
 * the strongest statement available about whether the provider measures it here at all. An account
 * that has ever recorded one order keeps its zeros, including a creative that genuinely sold
 * nothing this week; only an account that has never recorded one loses them, and it loses them to
 * «—», which is what nobody being able to tell looks like.
 *
 * Deliberately NOT used as evidence: that the figure is zero, that the objective is unusual, or
 * that the period is short. A zero is suspicious and suspicion is not provenance.
 */
final class CreativeResultAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Avail', 'slug' => 'av-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        $credential = new IntegrationCredential([
            'provider' => 'snapchat', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snap', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-avail',
            'name' => 'Snap',
            'status' => 'active',
        ]);

        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'snapchat', 'external_id' => 'cmp-avail', 'name' => 'Sales', 'status' => 'active',
            'objective' => 'sales',
        ]);
    }

    /**
     * Owner regression 1 — a purchase figure nobody can measure is not a measured zero.
     */
    public function test_a_result_the_account_has_never_measured_is_not_a_measured_zero(): void
    {
        $creative = $this->creative('cr-never-measured');
        $this->creativeRow($creative, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => 0]);

        $row = $this->figures($creative);

        $this->assertSame(
            'not_reported',
            $row['availability']['orders'] ?? null,
            'an account that has never recorded an order reported a measured zero for one',
        );
        $this->assertFalse(
            $row['reported']['orders'],
            '«الطلبات 0» is still being presented as a figure the provider sent',
        );
    }

    /**
     * Owner regression 2 — and a REAL zero survives, which is the half that is easy to lose.
     *
     * The same account, the same period, the same column: one creative sold twelve and this one sold
     * none. «0» is the answer here and dropping it to «—» would be the opposite defect — a creative
     * that measurably failed, presented as unmeasured.
     */
    public function test_a_real_zero_stays_a_zero_where_the_account_measures_the_result(): void
    {
        $sold = $this->creative('cr-sold');
        $this->creativeRow($sold, ['spend' => 50.0, 'impressions' => 10_000, 'clicks' => 300, 'conversions' => 12]);

        $none = $this->creative('cr-none');
        $this->creativeRow($none, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => 0]);

        $row = $this->figures($none);

        $this->assertSame('reported', $row['availability']['orders'] ?? null);
        $this->assertTrue($row['reported']['orders']);
        $this->assertSame(0.0, (float) $row['conversions']);
    }

    /**
     * Owner regression 3 — a creative that did not run reports nothing, rather than reporting zero.
     *
     * No rows in the window at all, so it is ABSENT from the figures map entirely and the card draws
     * its «no data» state. Asserted as the absence rather than as an availability reason, because
     * that is where this product already draws the line: a creative with no rows has no figures
     * object, and inventing one so it could carry «no_activity» would be inventing the very row
     * whose non-existence is the answer.
     *
     * «0 orders» here would be a claim about an ad that was not serving, and an operator reading it
     * would go looking for a creative problem that is a scheduling fact.
     */
    public function test_a_creative_with_no_delivery_in_the_window_reports_nothing_at_all(): void
    {
        $creative = $this->creative('cr-idle');

        $figures = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $this->assertArrayNotHasKey(
            (string) $creative->getKey(),
            $figures,
            'a creative that never ran was given a figures row, which is where a fabricated zero starts',
        );
    }

    /**
     * The evidence is the ACCOUNT's, not this creative's — so one creative's measured sale rescues
     * the zeros of every other creative on the same account.
     *
     * Asserted separately from the pair above because it is the rule that keeps this honest: without
     * it, «has this creative ever sold anything» would quietly become the test, and a creative that
     * has never sold would never be allowed to report a zero again.
     */
    public function test_the_evidence_is_the_accounts_history_not_this_creatives_week(): void
    {
        $sold = $this->creative('cr-sold-last-month');
        $this->creativeRow($sold, ['spend' => 10.0, 'impressions' => 100, 'clicks' => 4, 'conversions' => 3], Carbon::today()->subDays(60));

        $none = $this->creative('cr-none-this-week');
        $this->creativeRow($none, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => 0]);

        $row = $this->figures($none);

        $this->assertSame(
            'reported',
            $row['availability']['orders'] ?? null,
            'a zero was discarded although the account has measured orders before',
        );
    }

    private function figures(ExternalCreative $creative): array
    {
        return app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        )[(string) $creative->getKey()];
    }

    /** @param array<string, float|int|null> $figures */
    private function creativeRow(ExternalCreative $creative, array $figures, ?Carbon $day = null): void
    {
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'metric_date' => ($day ?? Carbon::today()->subDay())->toDateString(),
            ...$figures,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function creative(string $externalId): ExternalCreative
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'snapchat',
            'external_creative_id' => $externalId,
            'name' => $externalId,
            'format' => 'video',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'creative_id' => $creative->getKey(),
            'provider' => 'snapchat',
            'external_id' => 'ad-'.Str::random(6),
            'name' => 'Ad',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        return $creative;
    }
}
