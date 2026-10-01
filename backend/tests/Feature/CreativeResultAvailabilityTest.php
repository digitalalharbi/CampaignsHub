<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\ResultAvailability;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativeRows;
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
use Illuminate\Support\Facades\Artisan;
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
 * The pipeline is careful at its edge: `SnapchatConnector` skips a field the response omits or
 * sends as null, and `UpsertCreativeDailyMetrics` skips it again, so a metric nobody sent stays
 * NULL and reads as «not reported». That rule holds and is not what is being changed.
 *
 * The gap is one rung up. Snapchat is ASKED for `conversion_purchases` on every creative —
 * regardless of what the campaign was bought to do — and answers `0` for an account with no
 * purchase measurement configured, exactly as it answers `0` for an account that measures purchases
 * and sold none. Both arrive as a non-null zero.
 *
 * ## Why the answer is five states and not a boolean
 *
 * The first version of this asked one question — has this PROJECT ever recorded a non-zero through
 * this PROVIDER — and turned the answer into measured/unmeasured. Two holes, both of them the same
 * mistake in different directions:
 *
 *   - a project may hold several ad accounts of one provider, and account A's historical purchase
 *     cannot certify account B's zero. They are different advertisers' measurement setups;
 *   - «never observed a non-zero» is not proof of «cannot measure». A new sales account with a
 *     correctly installed pixel and no sales yet is indistinguishable, in the data, from an account
 *     with no pixel at all.
 *
 * So evidence that a metric IS measurable is positive and usable, and the absence of that evidence
 * proves nothing. The state for not knowing is {@see ResultAvailability::MeasurementUnverified}.
 */
final class CreativeResultAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $accountA;

    private ExternalAccount $accountB;

    private ExternalCampaign $campaignA;

    private ExternalCampaign $campaignB;

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

        /*
         * TWO ad accounts of one provider, in one project — the shape the first version could not
         * see. This is ordinary: an agency runs a client's own Snapchat account beside its own.
         */
        $this->accountA = $this->account($connection, 'act-a', 'Snap A');
        $this->accountB = $this->account($connection, 'act-b', 'Snap B');

        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaignA = $this->campaign($this->accountA, 'cmp-a');
        $this->campaignB = $this->campaign($this->accountB, 'cmp-b');
    }

    /**
     * Owner regression 1 — one account's history may not certify its neighbour's zero.
     *
     * Account A has sold something. Account B, in the same project and through the same provider
     * connection, has never proved it measures purchases at all. B's zero is not A's evidence to
     * lend: they are two advertisers' measurement setups that happen to be filed together.
     */
    public function test_one_accounts_history_does_not_license_another_accounts_zero(): void
    {
        $sold = $this->creative('cr-a-sold', $this->campaignA);
        $this->creativeRow($sold, ['spend' => 50.0, 'impressions' => 10_000, 'clicks' => 300, 'conversions' => 12]);

        $other = $this->creative('cr-b-zero', $this->campaignB);
        $this->creativeRow($other, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => 0]);

        $this->assertSame(
            ResultAvailability::MeasurementUnverified->value,
            $this->availability($other, 'orders'),
            'account B’s zero was certified by a purchase account A made',
        );
    }

    /**
     * Owner regression 2 — never having sold anything is not proof of being unable to measure.
     *
     * The exact account has no historical non-zero at all. That is the state of every new sales
     * account with a correctly installed pixel, and it is indistinguishable in the data from an
     * account with no pixel — so the honest answer is that nobody has verified it, NOT that the
     * platform does not report it.
     */
    public function test_an_account_with_no_history_is_unverified_rather_than_unreported(): void
    {
        $creative = $this->creative('cr-no-history', $this->campaignA);
        $this->creativeRow($creative, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => 0]);

        $this->assertSame(
            ResultAvailability::MeasurementUnverified->value,
            $this->availability($creative, 'orders'),
            'an unproven zero was reported as something the platform definitely does not send',
        );
    }

    /**
     * Owner regression 3 — and the exact account's own history DOES confirm its zero.
     *
     * The half that is easy to lose. A creative on an account that has measurably sold before, with
     * no sales this window, measurably sold nothing: «0» is the answer and dropping it to «—» would
     * be the opposite defect — a creative that measurably failed, presented as unmeasured.
     */
    public function test_the_exact_accounts_own_history_confirms_its_zero(): void
    {
        $sold = $this->creative('cr-a-earlier', $this->campaignA);
        $this->creativeRow($sold, ['spend' => 10.0, 'impressions' => 100, 'clicks' => 4, 'conversions' => 3], Carbon::today()->subDays(60));

        $none = $this->creative('cr-a-now', $this->campaignA);
        $this->creativeRow($none, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => 0]);

        $row = $this->figures($none);

        $this->assertSame(ResultAvailability::RealZeroConfirmed->value, $row['availability']['orders'] ?? null);
        $this->assertSame(0.0, (float) $row['conversions'], 'a confirmed zero stopped being shown as a zero');
    }

    /** Owner regression 4 — a field the provider never sent is still NOT_REPORTED, not a zero. */
    public function test_a_field_the_provider_omitted_stays_not_reported(): void
    {
        $creative = $this->creative('cr-omitted', $this->campaignA);
        $this->creativeRow($creative, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => null]);

        $this->assertSame(ResultAvailability::NotReported->value, $this->availability($creative, 'orders'));
    }

    /**
     * Owner regression 5 — a figure that exists for the campaign and is not this creative's.
     *
     * The creative reported no conversions of its own and its ads reported none either, while the
     * CAMPAIGN this creative ran in recorded purchases in the same window on the same account. The
     * number is real and it is not this creative's to claim: «0» would be a lie about the ad and
     * «not reported» would be a lie about the account.
     */
    public function test_a_campaign_figure_that_cannot_be_pinned_to_this_creative_is_not_attributable(): void
    {
        $creative = $this->creative('cr-unattributable', $this->campaignA);
        $this->creativeRow($creative, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => null]);

        $this->campaignResult($this->campaignA, 'conversions', 9.0);

        $this->assertSame(ResultAvailability::NotAttributable->value, $this->availability($creative, 'orders'));
    }

    /**
     * Owner regression 6 — an aggregate spanning accounts keeps the conservative truth.
     *
     * One creative's zero is confirmed by its own account's history and the other's is not. Summing
     * them does not make the pair confirmed: the headline strip over both is the one figure a client
     * reads first, and it may not inherit the stronger half's certainty.
     */
    public function test_an_aggregate_across_accounts_does_not_inherit_the_stronger_halfs_certainty(): void
    {
        $sold = $this->creative('cr-a-sold-before', $this->campaignA);
        $this->creativeRow($sold, ['spend' => 10.0, 'impressions' => 100, 'clicks' => 4, 'conversions' => 3], Carbon::today()->subDays(60));

        $confirmed = $this->creative('cr-a-zero', $this->campaignA);
        $this->creativeRow($confirmed, ['spend' => 20.0, 'impressions' => 900, 'clicks' => 20, 'conversions' => 0]);

        $unverified = $this->creative('cr-b-zero', $this->campaignB);
        $this->creativeRow($unverified, ['spend' => 20.0, 'impressions' => 900, 'clicks' => 20, 'conversions' => 0]);

        $strip = app(CreativeMetrics::class)->totalsFor(
            [(string) $confirmed->getKey(), (string) $unverified->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $this->assertNotNull($strip, 'the strip reported nothing at all for a scope that has figures');
        $this->assertSame(
            ResultAvailability::MeasurementUnverified->value,
            $strip['availability']['orders'] ?? null,
            'the strip certified a pooled zero on one account’s evidence',
        );
    }

    /**
     * Owner regression 7 — Content and Reports classify it the same way.
     *
     * «One creative + same account + same period must tell the same factual story everywhere.» The
     * roster is built from the same service, so this holds the thing that could still drift: a
     * surface stripping or re-deriving the state on its way out. Asserted against the LIBRARY's own
     * answer rather than against a literal, so the two move together or this fails.
     */
    public function test_the_report_roster_classifies_it_exactly_as_the_library_does(): void
    {
        $creative = $this->creative('cr-parity', $this->campaignA);
        $this->creativeRow($creative, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => 0]);

        $library = $this->figures($creative);

        $roster = app(CreativeRows::class)->lean(
            ExternalCreative::withoutGlobalScopes()->whereKey($creative->getKey())->get(),
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $row = collect($roster)->firstWhere('id', (string) $creative->getKey());

        $this->assertNotNull($row, 'the report roster lost the creative entirely');
        $this->assertSame(
            $library['availability']['orders'] ?? null,
            $row['metrics']['availability']['orders'] ?? null,
            'the report and the library disagree about what this creative’s zero means',
        );
    }

    /**
     * A creative that did not run reports nothing, rather than reporting zero.
     *
     * No rows in the window at all, so it is ABSENT from the figures map entirely and the card draws
     * its «no data» state. Asserted as the absence rather than as a state, because that is where
     * this product already draws the line: inventing a figures row so it could carry one would be
     * inventing the very row whose non-existence is the answer.
     */
    public function test_a_creative_with_no_delivery_in_the_window_reports_nothing_at_all(): void
    {
        $creative = $this->creative('cr-idle', $this->campaignA);

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
     * The instrument that answers «why» for ONE creative, on any estate.
     *
     * «For a representative creative report internally: creative, objective, provider, period,
     * creative-grain result, ad-grain result, coverage, final displayed result, why.» The rungs
     * print the first seven. The «why» cannot be reconstructed from the figures — a `0` and a `—`
     * look identical in a column — so it is printed with them.
     */
    public function test_the_reconcile_walk_says_why_a_result_is_not_a_figure(): void
    {
        $creative = $this->creative('cr-reconcile', $this->campaignA);
        $this->creativeRow($creative, ['spend' => 38.36, 'impressions' => 9_400, 'clicks' => 120, 'conversions' => 0]);

        Artisan::call('content:reconcile', ['creative' => (string) $creative->getKey()]);
        $printed = Artisan::output();

        $this->assertStringContainsString('results    :', $printed, 'the walk stopped printing the availability verdict');
        $this->assertStringContainsString('orders', $printed);
        $this->assertStringContainsString(
            ResultAvailability::MeasurementUnverified->value,
            $printed,
            'the walk no longer says WHY the result is a dash',
        );
    }

    private function availability(ExternalCreative $creative, string $key): ?string
    {
        return $this->figures($creative)['availability'][$key] ?? null;
    }

    /** @return array<string, mixed> */
    private function figures(ExternalCreative $creative): array
    {
        return app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        )[(string) $creative->getKey()];
    }

    private function account(ProviderConnection $connection, string $externalId, string $name): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function campaign(ExternalAccount $account, string $externalId): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'snapchat',
            'external_id' => $externalId,
            'name' => 'Sales',
            'status' => 'active',
            'objective' => 'sales',
        ]);
    }

    /** @param  array<string, float|int|null>  $figures */
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

    /** A figure the CAMPAIGN recorded, which no ad or creative under it accounts for. */
    private function campaignResult(ExternalCampaign $campaign, string $metricKey, float $value): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_account_id' => $campaign->external_account_id,
            'external_campaign_id' => $campaign->getKey(),
            'provider' => 'snapchat',
            'metric_key' => $metricKey,
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function creative(string $externalId, ExternalCampaign $campaign): ExternalCreative
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $campaign->getKey(),
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
            'external_campaign_id' => $campaign->getKey(),
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
