<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Rates\OfficialPegs;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Metrics\Support\SiblingRounding;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MONEY-RECON-RUNGS-001 — money reconciles exactly at every rung, on rows that do not divide evenly.
 *
 * ## Why the numbers are ugly on purpose
 *
 * `MoneyReconciliationLadderTest` seeds figures that convert to whole dollars (375 SAR = 100 USD), where every
 * rounding rule agrees. Real riyals do not: 100.00 SAR is 26.666667 USD, and three accounts each showing 26.67
 * add up to 80.01 beside a parent that says 80.00. This world is built from amounts whose conversions repeat, so
 * a rung that rounds its own sum independently of its parent's is caught here and nowhere else.
 *
 * ## The world
 *
 * Reporting unit USD (the fixed aggregation basis). Rate SAR→USD is the official 3.75 peg, stored exactly as the
 * rate feed stores it. Seven days, two platforms, four SELECTED ad accounts (Meta SAR, Meta USD, Snapchat SAR,
 * Snapchat USD), two campaigns each — plus rows that must NOT count: a DESELECTED account (binding inactive), an
 * account RE-BOUND to another project, and (separately) an account with no binding at all and one in EUR, for
 * which no rate is configured and none may be invented.
 *
 * Money enters through `ReportingCurrency::normalise()`, the very code the sync runs, so `original_amount` is
 * the provider's own figure and `value` is its conversion.
 */
final class MoneyReconciliationRungsTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-07-01';

    private const TO = '2026-07-07';

    private const WINDOW = 'from=2026-07-01&to=2026-07-07';

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    private Project $otherProject;

    private User $operator;

    /** @var array<string, ExternalAccount> */
    private array $accounts = [];

    /** @var array<string, list<UnifiedCampaign>> account key → its two campaigns */
    private array $campaigns = [];

    /**
     * What the provider says each INCLUDED account spent / earned, in the account's own currency, in integer minor units.
     *
     * @var array<string, array{spend: int, revenue: int, currency: string, provider: string}>
     */
    private array $provider = [];

    /** @var list<string> account keys whose rows count in the project */
    private array $selected = ['meta_sar', 'meta_usd', 'snap_sar', 'snap_usd'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Rungs', 'slug' => 'rungs-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'rungs-'.uniqid().'@k.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $this->workspace->id, 'name' => 'P', 'status' => 'active']);
        $this->otherProject = Project::create(['client_workspace_id' => $this->workspace->id, 'name' => 'Other', 'status' => 'active']);

        // The rate exactly as `CurrencyRateFeed` stores the peg: 1 SAR = 1/3.75 USD, source named. EUR deliberately has none.
        CurrencyRate::create([
            'base_currency' => 'SAR', 'quote_currency' => 'USD', 'rate' => OfficialPegs::rate('SAR', 'USD'),
            'rate_date' => '2026-01-01', 'source' => OfficialPegs::source('SAR', 'USD'),
        ]);
    }

    // ── 1. the provider's own figure survives, and the conversion is the peg ────────────────────────────

    public function test_original_amount_is_the_providers_figure_and_converted_is_original_over_the_peg(): void
    {
        $this->seedWorld();

        foreach ($this->selected as $key) {
            $rows = DB::table('daily_metrics')->where('external_account_id', $this->accounts[$key]->id)->where('metric_key', 'spend')->get();
            $this->assertCount(14, $rows, "{$key}: two campaigns x seven days");

            $original = 0;
            foreach ($rows as $row) {
                $this->assertSame($this->provider[$key]['currency'], trim((string) $row->original_currency));
                $original += (int) round(((float) $row->original_amount) * 100);
                $peg = $row->original_currency === 'SAR' ? 3.75 : 1.0;
                $this->assertEqualsWithDelta((float) $row->original_amount / $peg, (float) $row->value, 0.0000011, "{$key}: converted is not original / {$peg}");
            }
            // The figure a provider would be compared with: the account's own currency, to the minor unit, untouched by the conversion.
            $this->assertSame($this->provider[$key]['spend'], $original, "{$key}: stored original_amount is not what the provider reported");
        }
    }

    // ── 2. every rung of the web summary adds up to its parent, to the cent ─────────────────────────────

    public function test_every_rung_of_the_web_summary_sums_to_its_parent_to_the_cent(): void
    {
        $this->seedWorld();
        $expected = $this->expectedUsd();

        $summary = $this->read('metrics/summary')->json('data.current');
        $platforms = $this->read('metrics/platforms')->json('data');
        $accounts = $this->read('metrics/accounts')->json('data');
        $campaigns = $this->read('metrics/campaigns')->json('data');

        foreach (['spend', 'revenue'] as $key) {
            $this->assertSame($expected[$key], $this->cents($summary[$key]), "project {$key} is not the hand-computed total");
            foreach (['platform' => $platforms, 'account' => $accounts, 'campaign' => $campaigns] as $rung => $rows) {
                $this->assertSame($this->cents($summary[$key]), $this->sumCents($rows, $key), "the {$rung} rung's {$key} does not add up to the project's, to the cent");
            }
        }
        $this->assertCount(2, $platforms);
        $this->assertCount(4, $accounts);
        $this->assertCount(8, $campaigns);
    }

    public function test_ratios_at_every_rung_are_recomputed_from_that_rungs_own_converted_bases(): void
    {
        $this->seedWorld();
        $summary = $this->read('metrics/summary')->json('data.current');

        foreach (['platform' => $this->read('metrics/platforms')->json('data'), 'account' => $this->read('metrics/accounts')->json('data')] as $rung => $rows) {
            foreach ($rows as $row) {
                $this->assertEqualsWithDelta(round((float) $row['revenue'] / (float) $row['spend'], 2), round((float) $row['roas'], 2), 0.011, "{$rung} ROAS is not revenue over spend");
                $this->assertEqualsWithDelta(round((float) $row['spend'] / (float) $row['conversions'], 2), round((float) $row['cpa'], 2), 0.011, "{$rung} cost per result is not spend over results");
            }
        }
        $this->assertEqualsWithDelta(round((float) $summary['revenue'] / (float) $summary['spend'], 2), round((float) $summary['roas'], 2), 0.011);
    }

    // ── 3. deselected, re-bound and unbound accounts ────────────────────────────────────────────────────

    public function test_a_deselected_and_a_rebound_account_are_excluded_at_every_rung(): void
    {
        $this->seedWorld(withExcluded: true);
        $expected = $this->expectedUsd();

        $summary = $this->read('metrics/summary')->json('data.current');
        $accounts = $this->read('metrics/accounts')->json('data');
        $platforms = $this->read('metrics/platforms')->json('data');
        $campaigns = $this->read('metrics/campaigns')->json('data');

        $this->assertSame($expected['spend'], $this->cents($summary['spend']), 'excluded accounts leaked into the project total');
        $this->assertEqualsCanonicalizing(
            array_map(fn (string $k): string => (string) $this->accounts[$k]->id, $this->selected),
            array_column($accounts, 'account_id'),
            'the account rung lists an account the project has deselected or re-bound',
        );
        $this->assertSame($expected['spend'], $this->sumCents($platforms, 'spend'));
        $this->assertSame($expected['spend'], $this->sumCents($campaigns, 'spend'));
        $this->assertCount(8, $campaigns, 'a campaign of an excluded account is on the campaign rung');

        // Re-selecting the deselected account brings its history back — nothing was deleted, only not shown.
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->accounts['meta_off']->id)->update(['is_active' => true]);
        $withIt = $this->read('metrics/summary')->json('data.current.spend');
        $this->assertGreaterThan($expected['spend'] / 100, (float) $withIt, 'the deselected account\'s history was lost rather than hidden');
    }

    public function test_an_account_with_no_binding_at_all_follows_the_standing_policy_on_every_rung_alike(): void
    {
        $this->seedWorld(withUnbound: true);
        $expected = $this->expectedUsd();
        $selectedUsd = array_sum(array_map(fn (string $k): float => $this->usdOf($k, 'spend'), $this->selected));
        $withUnboundUsd = $selectedUsd + $this->usdOf('tiktok_sar', 'spend');

        $summary = $this->read('metrics/summary')->json('data.current');
        $accounts = array_column($this->read('metrics/accounts')->json('data'), 'account_id');
        $inProject = in_array((string) $this->accounts['tiktok_sar']->id, $accounts, true);

        // `BoundAccountVisibility::UNBOUND_ROWS_VISIBLE` is the Owner-pending policy for never-bound accounts. Whatever it is, the
        // total and the account rung must obey it TOGETHER; a total that counts the account while the rung omits it is the defect.
        $this->assertSame(BoundAccountVisibility::UNBOUND_ROWS_VISIBLE, $inProject, 'the account rung and the policy disagree');
        $this->assertSame($this->cents($inProject ? $withUnboundUsd : $selectedUsd), $this->cents($summary['spend']), 'the total and the account rung treat the never-bound account differently');
        $this->assertSame($this->cents($summary['spend']), $this->sumCents($this->read('metrics/platforms')->json('data'), 'spend'));

        // A link that NAMES its accounts never includes an account it did not name, bound or not.
        $live = $this->live(['meta_sar', 'meta_usd', 'snap_sar', 'snap_usd']);
        $this->assertSame($expected['spend'], $this->cents($live['totals']['spend']), 'a link scoped to four accounts counted a fifth');
    }

    // ── 4. one figure on the web summary, the live report and the export ────────────────────────────────

    public function test_the_web_summary_the_live_report_and_the_export_state_the_same_figures_for_the_window(): void
    {
        $this->seedWorld(withExcluded: true);
        $expected = $this->expectedUsd();

        $summary = $this->read('metrics/summary')->json('data.current');
        $created = $this->createLive($this->selected);
        $live = $this->getJson("/api/v1/reports/shared/{$created['token']}/live")->assertOk()->json('data');
        $csv = $this->csvOf($created['report_id']);

        foreach (['spend', 'revenue'] as $key) {
            $this->assertSame($expected[$key], $this->cents($summary[$key]), "web summary {$key}");
            $this->assertSame($expected[$key], $this->cents($live['totals'][$key]), "live report {$key}");
            $this->assertSame($expected[$key], $this->cents($this->csvKpi($csv, $key)), "export {$key}");
        }

        // The platform rung, the same on all three, and adding up on all three.
        $webPlatforms = collect($this->read('metrics/platforms')->json('data'))->keyBy('provider');
        $csvPlatforms = $this->csvPlatforms($csv);
        foreach (['meta', 'snapchat'] as $provider) {
            $this->assertNotNull($webPlatforms[$provider] ?? null);
            $livePlatform = collect($live['platforms'])->firstWhere('provider', $provider);
            $this->assertNotNull($livePlatform, "live report lost {$provider}");
            $this->assertSame($this->cents($webPlatforms[$provider]['spend']), $this->cents($livePlatform['spend']), "{$provider} web vs live");
            $this->assertSame($this->cents($webPlatforms[$provider]['spend']), $this->cents($csvPlatforms[$provider] ?? null), "{$provider} web vs export");
        }
        $this->assertSame($expected['spend'], $this->sumCents($live['platforms'], 'spend'), 'live platforms do not add up to the live total');
        $this->assertSame($expected['spend'], (int) round(array_sum($csvPlatforms) * 100), 'export platforms do not add up to the export total');
        $this->assertSame($expected['spend'], $this->sumCents($live['campaign_rows'] ?? [], 'spend'), 'live campaign rows do not add up to the live total');
    }

    // ── 5. the portfolio rung ────────────────────────────────────────────────────────────────────────────

    public function test_the_portfolio_total_trend_and_contribution_equal_the_projects_spend_to_the_cent(): void
    {
        $this->seedWorld(withExcluded: true);
        $expected = $this->expectedUsd();

        $summary = $this->read('metrics/summary')->json('data.current');
        $portfolio = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/portfolio/overview?'.self::WINDOW)->assertOk()->json('data');

        $usd = fn (array $rows) => collect($rows)->firstWhere('currency', 'USD');
        $headline = $usd($portfolio['spend']['by_currency']);
        $trend = $usd($portfolio['trend']['by_currency']);
        $contribution = $usd($portfolio['contribution']['by_currency']);

        $this->assertNotNull($headline, 'the portfolio has no USD figure');
        $this->assertSame($expected['spend'], $this->cents($summary['spend']));
        $this->assertSame($this->cents($summary['spend']), $this->cents($headline['spend']), 'the portfolio headline is not the project total');
        $this->assertSame($this->cents($headline['spend']), $this->sumCents($trend['points'], 'spend'), 'the portfolio trend does not add up to its headline, to the cent');
        $this->assertSame($this->cents($headline['spend']), $this->sumCents($contribution['projects'], 'spend'), 'the portfolio contribution does not add up to its headline, to the cent');
    }

    // ── 6. EUR has no configured rate: withheld on every surface, never guessed ──────────────────────────

    public function test_unconverted_eur_is_withheld_and_stated_on_summary_live_and_export_never_defaulted(): void
    {
        $this->seedWorld(withEur: true);
        $this->assertSame(0, CurrencyRate::query()->where('base_currency', 'EUR')->orWhere('quote_currency', 'EUR')->count(), 'a EUR rate exists in the test world');
        $expected = $this->expectedUsd();

        $summary = $this->read('metrics/summary')->json('data.current');
        $created = $this->createLive(['meta_sar', 'meta_usd', 'snap_sar', 'snap_usd', 'meta_eur']);
        $live = $this->getJson("/api/v1/reports/shared/{$created['token']}/live")->assertOk()->json('data');

        $this->assertSame($expected['spend'], $this->cents($summary['spend']), 'the converted total moved when a EUR day was added');
        $this->assertSame($expected['spend'], $this->cents($live['totals']['spend']));
        foreach ([$summary, $live['totals']] as $totals) {
            // Two campaigns x seven days; the second reports a genuine zero, which is still a row whose conversion was not possible.
            $this->assertSame(14, (int) $totals['spend_withheld_rows'], 'every EUR spend row is withheld');
            $this->assertSame('EUR', $totals['money_original_currency']);
            $this->assertSame(
                (int) round(array_sum(array_map(fn (int $d): float => 40.0 + $d * 1.5, range(0, 6))) * 100),
                $this->cents($totals['spend_original']),
                'the withheld EUR amount is not the provider\'s own figure',
            );
            $this->assertNull($totals['cpa'] ?? null, 'a cost ratio was printed over incomplete money');
            $this->assertNull($totals['roas'] ?? null, 'ROAS was printed over incomplete money');
        }
        $account = collect($this->read('metrics/accounts')->json('data'))->firstWhere('account_id', (string) $this->accounts['meta_eur']->id);
        $this->assertSame(14, (int) $account['spend_withheld_rows'], 'the account rung lost the withheld days');
        $this->assertEqualsWithDelta(0.0, (float) $account['spend'], 0.0, 'the EUR account was given a converted figure');
    }

    public function test_apportionment_keeps_every_child_within_one_cent_of_its_own_rounding_and_the_set_on_its_parent(): void
    {
        foreach ([[26.666667, 26.666667, 26.666667], [0.004, 0.004, 0.004, 0.004, 0.004], [10.005, 20.005, 30.005], [0.0, 0.0], [99.994999, 0.005001], [1234.567891]] as $exact) {
            $cents = SiblingRounding::cents($exact);
            $this->assertSame((int) round(round(array_sum($exact), 2) * 100), array_sum($cents), 'set does not sum to its parent: '.json_encode($exact));
            foreach ($exact as $i => $amount) {
                $this->assertLessThanOrEqual(1, abs($cents[$i] - (int) round($amount * 100)), "child {$i} moved more than one cent from its own rounding");
            }
        }
    }

    // ── 7. the same rounding on every child: the case that makes independent rounding disagree ────────────

    /**
     * 100.00 SAR is 26.666667 USD. Five such accounts are 133.333335 — 133.33 — but every child rounds to 26.67, and five
     * of those print 133.35. The ladder is built so each rung's children round UP together, which is the one shape on which
     * «round each rung on its own» cannot add up. Project P holds three accounts (one per platform, one day each), and two
     * more projects hold one each, so the account, campaign, platform, trend-day and contribution rungs all see it.
     */
    public function test_rungs_add_up_to_the_cent_when_every_child_rounds_the_same_way(): void
    {
        $q1 = Project::create(['client_workspace_id' => $this->workspace->id, 'name' => 'Q1', 'status' => 'active']);
        $q2 = Project::create(['client_workspace_id' => $this->workspace->id, 'name' => 'Q2', 'status' => 'active']);
        $normaliser = app(ReportingCurrency::class);
        $rows = [];
        foreach ([['a', 'meta', $this->project, 1], ['b', 'snapchat', $this->project, 2], ['c', 'tiktok', $this->project, 3], ['d', 'meta', $q1, 4], ['e', 'meta', $q2, 5]] as [$key, $provider, $home, $day]) {
            $this->accounts[$key] = $this->account($key, $provider, 'SAR', 'bound', $home);
            $unified = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $home->id, 'name' => "h-{$key}", 'status' => 'active', 'objective' => 'sales']);
            $external = ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $home->id, 'external_account_id' => $this->accounts[$key]->id,
                'unified_campaign_id' => $unified->id, 'provider' => $provider, 'external_id' => "ext-h-{$key}", 'name' => "h-{$key}", 'status' => 'active',
            ]);
            $date = Carbon::parse(self::FROM)->addDays($day);
            foreach (['spend' => 100.00, 'revenue' => 250.00] as $metric => $amount) {
                $money = $normaliser->normalise($amount, 'SAR', 'USD', $date);
                $rows[] = $this->metric($key, $provider, $unified, $external, $metric, $date, $money['value'], $money, $home);
            }
        }
        app(UpsertDailyMetrics::class)->handle($rows);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        // Hand-computed: three accounts of 100 SAR = 80.000001 USD -> 80.00 (not 3 x 26.67 = 80.01); revenue 250 SAR x 3 = 200.000001 -> 200.00 (3 x 66.67 = 200.01).
        $summary = $this->read('metrics/summary')->json('data.current');
        $this->assertSame(8000, $this->cents($summary['spend']));
        $this->assertSame(20000, $this->cents($summary['revenue']));

        $web = ['platform' => $this->read('metrics/platforms')->json('data'), 'account' => $this->read('metrics/accounts')->json('data'), 'campaign' => $this->read('metrics/campaigns')->json('data')];
        foreach ($web as $rung => $children) {
            $this->assertCount(3, $children);
            $this->assertSame(8000, $this->sumCents($children, 'spend'), "web {$rung} rung: three children of 26.67 do not add up to the project's 80.00");
            $this->assertSame(20000, $this->sumCents($children, 'revenue'), "web {$rung} rung revenue");
        }

        $created = $this->createLive(['a', 'b', 'c']);
        $live = $this->getJson("/api/v1/reports/shared/{$created['token']}/live")->assertOk()->json('data');
        $csv = $this->csvOf($created['report_id']);
        $this->assertSame(8000, $this->cents($live['totals']['spend']));
        $this->assertSame(8000, $this->sumCents($live['platforms'], 'spend'), 'live platform rung');
        $this->assertSame(8000, $this->sumCents($live['campaign_rows'] ?? [], 'spend'), 'live campaign rung');
        $this->assertSame(8000, (int) round(array_sum($this->csvPlatforms($csv)) * 100), 'export platform rung');
        $this->assertSame(8000, $this->cents($this->csvKpi($csv, 'spend')), 'export total');

        // Portfolio: P + Q1 + Q2 = 5 x 26.666667 = 133.333335 -> 133.33, never 5 x 26.67 = 133.35.
        $portfolio = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/portfolio/overview?'.self::WINDOW)->assertOk()->json('data');
        $usd = fn (array $rows) => collect($rows)->firstWhere('currency', 'USD');
        $headline = $this->cents($usd($portfolio['spend']['by_currency'])['spend']);
        $this->assertSame(13333, $headline);
        $this->assertSame($headline, $this->sumCents($usd($portfolio['trend']['by_currency'])['points'], 'spend'), 'portfolio trend: five days of 26.67');
        $this->assertSame($headline, $this->sumCents($usd($portfolio['contribution']['by_currency'])['projects'], 'spend'), 'portfolio contribution: three projects');
    }

    // ── world ─────────────────────────────────────────────────────────────────────────────────────────

    /** Provider-side spend for a day, in the account's own currency, awkward on purpose. */
    private function dayAmount(string $key, string $metric, int $campaign, int $day): float
    {
        $base = match ($key) {
            'meta_sar' => 100.00, 'snap_sar' => 33.33, 'meta_usd' => 12.34, 'snap_usd' => 7.77,
            'meta_off' => 91.91, 'rebound_usd' => 55.55, 'tiktok_sar' => 64.64, default => 0.0,
        };

        return round($base + $campaign * 7.07 + $day * 1.11 + ($metric === 'revenue' ? $base * 2.3 : 0.0), 2);
    }

    private function seedWorld(bool $withExcluded = false, bool $withUnbound = false, bool $withEur = false): void
    {
        $plan = [
            'meta_sar' => ['meta', 'SAR', 'bound'], 'meta_usd' => ['meta', 'USD', 'bound'],
            'snap_sar' => ['snapchat', 'SAR', 'bound'], 'snap_usd' => ['snapchat', 'USD', 'bound'],
        ];
        if ($withExcluded) {
            $plan['meta_off'] = ['meta', 'SAR', 'deselected'];
            $plan['rebound_usd'] = ['snapchat', 'USD', 'rebound'];
        }
        if ($withUnbound) {
            $plan['tiktok_sar'] = ['tiktok', 'SAR', 'none'];
        }
        if ($withEur) {
            $plan['meta_eur'] = ['meta', 'EUR', 'bound'];
        }

        $rows = [];
        $normaliser = app(ReportingCurrency::class);
        foreach ($plan as $key => [$provider, $currency, $binding]) {
            $this->accounts[$key] = $this->account($key, $provider, $currency, $binding);
            if ($key === 'meta_eur') {
                $this->selected[] = $key;
            }
            $this->campaigns[$key] = [];
            $this->provider[$key] = ['spend' => 0, 'revenue' => 0, 'currency' => $currency, 'provider' => $provider];

            foreach ([0, 1] as $c) {
                $unified = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => "{$key}-{$c}", 'status' => 'active', 'objective' => 'sales']);
                $this->campaigns[$key][] = $unified;
                $external = ExternalCampaign::withoutGlobalScopes()->create([
                    'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->accounts[$key]->id,
                    'unified_campaign_id' => $unified->id, 'provider' => $provider, 'external_id' => "ext-{$key}-{$c}", 'name' => "{$key}-{$c}", 'status' => 'active',
                ]);

                for ($day = 0; $day < 7; $day++) {
                    $date = Carbon::parse(self::FROM)->addDays($day);
                    $amounts = $key === 'meta_eur'
                        ? ['spend' => 40.0 + $day * 1.5 + 0.0, 'revenue' => 0.0]
                        : ['spend' => $this->dayAmount($key, 'spend', $c, $day), 'revenue' => $this->dayAmount($key, 'revenue', $c, $day)];
                    if ($key === 'meta_eur' && $c === 1) {
                        $amounts = ['spend' => 0.0, 'revenue' => 0.0];
                    }
                    foreach ($amounts as $metric => $amount) {
                        $this->provider[$key][$metric] += (int) round($amount * 100);
                        $money = $normaliser->normalise($amount, $currency, 'USD', $date);
                        $rows[] = $this->metric($key, $provider, $unified, $external, $metric, $date, $money['value'], $money);
                    }
                    foreach (['conversions' => 3.0 + $c + $day, 'impressions' => 1000.0 + $day, 'clicks' => 40.0 + $c] as $metric => $value) {
                        $rows[] = $this->metric($key, $provider, $unified, $external, $metric, $date, $value, null);
                    }
                }
            }
        }
        app(UpsertDailyMetrics::class)->handle($rows);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** Hand-computed USD cents of the SELECTED accounts: provider amount (own currency) / peg, summed row by row then rounded once. */
    private function expectedUsd(): array
    {
        $out = ['spend' => 0, 'revenue' => 0];
        foreach (['spend', 'revenue'] as $metric) {
            $out[$metric] = $this->cents(array_sum(array_map(fn (string $k): float => $this->usdOf($k, $metric), array_diff($this->selected, ['meta_eur']))));
        }

        return $out;
    }

    private function usdOf(string $key, string $metric): float
    {
        $sum = 0.0;
        foreach ([0, 1] as $c) {
            for ($day = 0; $day < 7; $day++) {
                $sum += round($this->dayAmount($key, $metric, $c, $day) / ($this->provider[$key]['currency'] === 'SAR' ? 3.75 : 1.0), 6);
            }
        }

        return $sum;
    }

    private function account(string $key, string $provider, string $currency, string $binding, ?Project $home = null): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: "{$provider}-{$key}",
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => "ad-{$key}", 'name' => $key, 'currency' => $currency, 'status' => 'active',
        ]);
        if ($binding !== 'none') {
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id,
                'project_id' => $binding === 'rebound' ? $this->otherProject->id : ($home ?? $this->project)->id,
                'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'advertising', 'is_active' => $binding !== 'deselected',
            ]);
        }

        return $account;
    }

    /** @param array<string, mixed>|null $money */
    private function metric(string $key, string $provider, UnifiedCampaign $unified, ExternalCampaign $external, string $metric, Carbon $date, ?float $value, ?array $money, ?Project $home = null): NormalizedMetric
    {
        return new NormalizedMetric(
            tenantId: (string) $this->tenant->id, projectId: (string) ($home ?? $this->project)->id, provider: $provider,
            externalAccountId: (string) $this->accounts[$key]->id, externalCampaignId: (string) $external->getKey(), unifiedCampaignId: (string) $unified->id,
            metricDate: $date, metricKey: $metric, value: $value,
            originalCurrency: $money['original_currency'] ?? null, projectCurrency: $money['project_currency'] ?? null,
            originalAmount: $money['original_amount'] ?? null, convertedAmount: $money['converted_amount'] ?? null,
            exchangeRate: $money['exchange_rate'] ?? null, rateDate: $money['rate_date'] ?? null, rateSource: $money['rate_source'] ?? null,
            originalTimezone: 'UTC', projectTimezone: 'Asia/Riyadh', sourceType: 'api', dataFreshnessAt: $date->copy()->endOfDay(),
        );
    }

    // ── surfaces ──────────────────────────────────────────────────────────────────────────────────────

    private function read(string $path)
    {
        return $this->actingAs($this->operator, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/{$path}?".self::WINDOW)->assertOk();
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function createLive(array $keys): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports/live", [
                'name' => 'Rungs', 'account_ids' => array_map(fn (string $k): string => (string) $this->accounts[$k]->id, $keys),
                'from' => self::FROM, 'to' => self::TO, 'form' => 'detailed', 'detail_level' => 'detailed', 'campaign_breakdown' => true,
                'hide_spend' => false, 'hide_revenue' => false, 'allow_download' => true,
                'metrics' => ['spend', 'revenue', 'conversions', 'roas', 'cpa'],
            ])->assertCreated()->json('data');
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function live(array $keys): array
    {
        $created = $this->createLive($keys);

        return $this->getJson("/api/v1/reports/shared/{$created['token']}/live")->assertOk()->json('data');
    }

    private function csvOf(string $reportId): string
    {
        return app(ReportExporter::class)->render(Report::withoutGlobalScopes()->findOrFail($reportId), 'csv');
    }

    private function csvKpi(string $csv, string $key): ?string
    {
        return preg_match('/^'.$key.',([0-9.\-]+)$/m', $csv, $m) === 1 ? $m[1] : null;
    }

    /** @return array<string, float> provider → spend column of the CSV's platform block */
    private function csvPlatforms(string $csv): array
    {
        $out = [];
        $block = explode("\n\n", substr($csv, (int) strpos($csv, "\nPlatforms,")))[0] ?? '';
        foreach (explode("\n", trim($block)) as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            if (in_array($cells[0] ?? '', ['meta', 'snapchat', 'tiktok'], true)) {
                $out[$cells[0]] = (float) ($cells[1] ?? 0);
            }
        }

        return $out;
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** @param array<int, array<string, mixed>>|null $rows */
    private function sumCents(?array $rows, string $key): int
    {
        return array_sum(array_map(fn (array $r): int => $this->cents($r[$key] ?? 0), $rows ?? []));
    }
}
