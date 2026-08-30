<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\Models\SpendLimitEvent;
use App\Domains\Metrics\Services\SpendLimitGovernor;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetricDefinitionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUDGET-GOVERNANCE-001 — the workspace's own spend limits, and the line they must never cross.
 *
 * ## The distinction the whole feature rests on
 *
 * `unified_campaigns.total_budget` is the plan set INSIDE the ad platform, and the platform enforces
 * it: when it is exhausted, delivery stops. A `spend_limits` row is a limit an agency sets for
 * ITSELF, over a scope no single platform can see — «10,000 SAR across every connected platform this
 * month» — and NOTHING enforces it.
 *
 * An operator who believes otherwise will not go and pause the campaigns, and the money keeps going
 * out with a green screen in front of it. That is why `enforcement: internal_monitoring` is asserted
 * on every reading here, in every state, including the ones where no figure could be computed.
 *
 * ## And why «unknown» is a state
 *
 * Spend withheld for want of an exchange rate, or denominated differently from the limit, gives no
 * comparable figure at all. A governance surface that printed «0% used» for it would be reporting
 * safety it cannot see — the one failure this table exists to prevent, arriving through the feature
 * meant to prevent it.
 */
final class SpendLimitGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private const TODAY = '2026-08-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'lim-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── What the limit says about itself ─────────────────────────────────────────────────────────

    /** Every reading carries the word, in every state — including the ones with no figures. */
    public function test_a_limit_always_says_that_nothing_enforces_it(): void
    {
        // Real spend in dollars, and a limit in euros — the state where no figure can be computed.
        $this->spend(4_500, '2026-08-02');

        $ok = $this->read($this->limit(10_000));
        $unknown = $this->read($this->limit(10_000, currency: 'EUR'));

        $this->assertSame('internal_monitoring', $ok['enforcement']);
        $this->assertSame('internal_monitoring', $unknown['enforcement'], 'the word must survive a state with no figures');
        $this->assertSame('unknown', $unknown['state']);
    }

    // ── The arithmetic ───────────────────────────────────────────────────────────────────────────

    /**
     * Allocated, consumed, remaining, utilisation and pace over a period that is 15 days in.
     *
     * 4,500 spent of 10,000 on day 15 of 31 is 45% used against 48.4% of the period elapsed — a pace
     * of 0.93, slightly under plan. The projection extrapolates the whole period: 4,500 ÷ 0.484.
     */
    public function test_it_states_consumption_pace_and_a_projection_for_the_period(): void
    {
        $this->spend(1_500, '2026-08-02');
        $this->spend(1_500, '2026-08-08');
        $this->spend(1_500, '2026-08-14');

        $row = $this->read($this->limit(10_000));

        $this->assertSame('comparable', $row['basis']);
        $this->assertSame(10_000.0, $row['amount']);
        $this->assertEqualsWithDelta(4_500.0, $row['consumed'], 0.01);
        $this->assertEqualsWithDelta(5_500.0, $row['remaining'], 0.01);
        $this->assertEqualsWithDelta(0.45, $row['utilisation'], 0.001);
        $this->assertEqualsWithDelta(0.93, $row['pace'], 0.01, 'pace is against the ELAPSED share of the limit');
        $this->assertEqualsWithDelta(9_300.0, $row['projected_period_spend'], 50.0);
        $this->assertSame('ok', $row['state']);
    }

    /** Past the highest warning threshold but not the limit: approaching, not over. */
    public function test_a_limit_past_its_warning_threshold_is_approaching(): void
    {
        $this->spend(8_500, '2026-08-05');

        $row = $this->read($this->limit(10_000, thresholds: [50, 80]));

        $this->assertSame('approaching', $row['state']);
        $this->assertSame([50, 80, 100], $row['thresholds'], '100 is never optional — it is the limit itself');
    }

    /** At or past the limit is over, and remaining goes negative rather than clamping to zero. */
    public function test_a_limit_that_was_exceeded_says_so_and_states_the_overspend(): void
    {
        $this->spend(11_200, '2026-08-05');

        $row = $this->read($this->limit(10_000));

        $this->assertSame('over', $row['state']);
        $this->assertEqualsWithDelta(-1_200.0, $row['remaining'], 0.01, 'clamping the overspend to zero hides the amount that matters');
    }

    // ── The projection, and when it is withheld ──────────────────────────────────────────────────

    /**
     * «You will reach this on the 21st» — 500/day against 3,000 remaining, from day 14.
     *
     * The date is the sentence an operator acts on, so it is stated only where a rate stands behind
     * it. The three refusals below are what make this one worth believing.
     */
    public function test_it_names_the_day_the_limit_will_be_reached(): void
    {
        foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $day) {
            $this->spend(1_000, $day);
        }

        $row = $this->read($this->limit(10_000));

        // 7,000 over 15 elapsed days is 466.67/day; 3,000 remaining is 6.4 days → 7, from the 15th.
        $this->assertSame('projected', $row['projected_exhaustion']['reason']);
        $this->assertSame('2026-08-22', $row['projected_exhaustion']['date']);
    }

    /** One day of spend multiplied by thirty is not a forecast. */
    public function test_a_period_that_has_barely_started_projects_nothing(): void
    {
        $this->spend(400, '2026-08-14');

        $row = $this->read($this->limit(10_000, from: '2026-08-14', to: '2026-09-13'));

        $this->assertNull($row['projected_exhaustion']['date']);
        $this->assertSame('too_early', $row['projected_exhaustion']['reason']);
    }

    /** Nothing spent is not «never»: there is no rate, so there is no date. */
    public function test_a_limit_with_no_spend_projects_nothing(): void
    {
        $row = $this->read($this->limit(10_000));

        $this->assertNull($row['projected_exhaustion']['date']);
        $this->assertSame('no_spend_rate', $row['projected_exhaustion']['reason']);
        $this->assertSame('ok', $row['state']);
    }

    /** A date past the window is a date nobody is measuring — the honest answer is the window. */
    public function test_a_limit_that_will_not_be_reached_this_period_gives_no_date(): void
    {
        $this->spend(100, '2026-08-02');
        $this->spend(100, '2026-08-08');

        $row = $this->read($this->limit(10_000));

        $this->assertNull($row['projected_exhaustion']['date']);
        $this->assertSame('not_within_period', $row['projected_exhaustion']['reason']);
    }

    // ── The refusals ─────────────────────────────────────────────────────────────────────────────

    /**
     * Spend that could not be converted has no single figure, so there is nothing to compare.
     *
     * The tempting answer is to pace against the converted part. It is smaller than the truth, which
     * on THIS surface means telling somebody they have room they do not have.
     */
    public function test_partly_withheld_spend_is_unknown_rather_than_understated(): void
    {
        $this->spend(2_000, '2026-08-02');
        $this->withheldSpend(3_000, '2026-08-03', 'EUR');

        $row = $this->read($this->limit(10_000));

        $this->assertSame('unknown', $row['state']);
        $this->assertSame('partial', $row['basis']);
        /*
         * `consumed` itself, not only the derivations.
         *
         * Without this line the safety came from the CURRENCY being unnameable for a partial scope,
         * which is true and is not the reason: a mapping that fell back to the converted 2,000 passed
         * every other assertion here. The figure is the thing that gets read, so the figure is what
         * is asserted absent.
         */
        $this->assertNull($row['consumed'], '2,000 of a 5,000 scope is not «what was spent» — it is the part that could be converted');
        $this->assertNull($row['utilisation'], 'a percentage here would be a claim about money nobody could convert');
        $this->assertNull($row['remaining']);
    }

    /** A limit in euros against spend in dollars is not 45% used; it is not comparable at all. */
    public function test_a_limit_in_another_currency_is_refused_rather_than_divided(): void
    {
        $this->spend(4_500, '2026-08-02');

        $row = $this->read($this->limit(10_000, currency: 'EUR'));

        $this->assertSame('unknown', $row['state']);
        $this->assertSame('currency_mismatch', $row['basis']);
        $this->assertNull($row['utilisation']);
    }

    // ── Scope ────────────────────────────────────────────────────────────────────────────────────

    /** A platform limit measures that platform's spend, not the project's. */
    public function test_a_platform_limit_counts_only_that_platform(): void
    {
        $this->spend(4_000, '2026-08-02', provider: 'meta');
        $this->spend(6_000, '2026-08-03', provider: 'tiktok');

        $project = $this->read($this->limit(20_000));
        $tiktok = $this->read($this->limit(20_000, scope: SpendLimitScope::Platform, scopeId: 'tiktok'));

        $this->assertEqualsWithDelta(10_000.0, $project['consumed'], 0.01);
        $this->assertEqualsWithDelta(6_000.0, $tiktok['consumed'], 0.01, 'a TikTok limit measured against every platform is a cap that fires on day one');
    }

    /** Nothing spent is 0% used, not «unknown» — zero is zero in every currency. */
    public function test_a_limit_with_no_spend_at_all_is_comparable_and_empty(): void
    {
        $row = $this->read($this->limit(10_000, currency: 'EUR'));

        $this->assertSame('comparable', $row['basis'], 'with no rows there is no project currency to read, and zero still needs no conversion');
        $this->assertSame(0.0, $row['utilisation']);
        $this->assertSame('ok', $row['state']);
    }

    // ── The API ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The envelope says it too, in words, in both languages.
     *
     * A row's `enforcement` key is for code. `meta` is for the person who exports this payload into a
     * report, where the figure travels and the page header does not.
     */
    public function test_the_list_endpoint_states_that_nothing_is_enforced(): void
    {
        [$owner] = $this->owner();
        $this->limit(10_000);

        $res = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/spend-limits")
            ->assertOk();

        $this->assertSame('internal_monitoring', $res->json('meta.enforcement'));
        $this->assertStringContainsString('does not stop delivery', (string) $res->json('meta.enforcement_note_en'));
        $this->assertStringContainsString('لا يوقف عرض الإعلانات', (string) $res->json('meta.enforcement_note_ar'));
        $this->assertSame('internal_monitoring', $res->json('data.0.enforcement'));
    }

    /**
     * A platform limit with no platform named would silently become «the whole project».
     *
     * A 4,000 TikTok cap measured against every platform's spend reads «over» on the first day, and
     * an operator who meets that once stops believing the next one.
     */
    public function test_a_scoped_limit_without_its_scope_is_refused(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/spend-limits", [
                'scope' => 'platform',
                'amount' => 4_000,
                'currency' => 'USD',
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-08-31',
            ])
            ->assertStatus(422);

        $this->assertSame(0, SpendLimit::withoutGlobalScopes()->count());
    }

    /** Deactivated rather than deleted — an audit trail whose subject can vanish is not one. */
    public function test_removing_a_limit_keeps_it_for_the_record(): void
    {
        [$owner] = $this->owner();
        $limit = $this->limit(10_000);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/spend-limits/{$limit->id}")
            ->assertOk();

        $this->assertFalse((bool) $limit->refresh()->active);
        $this->assertSame(1, SpendLimit::withoutGlobalScopes()->count());
    }

    // ── The alert, and its audit ─────────────────────────────────────────────────────────────────

    /**
     * A crossed threshold is announced ONCE, by the detector that already watches platform budgets.
     *
     * One detector, deliberately: a second engine watching a second kind of budget would eventually
     * disagree with this one about the same campaign's spend, and the customer would meet both.
     *
     * The alert says «internal» in as many words. A generic «budget at risk» read by somebody who
     * assumes the platform will stop is the exact misunderstanding this whole feature has to avoid.
     */
    public function test_a_crossed_threshold_is_announced_once_and_recorded_with_its_figures(): void
    {
        $this->spend(8_500, '2026-08-05');
        $limit = $this->limit(10_000, thresholds: [80]);

        $rule = AlertRule::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'type' => 'budget_risk', 'name' => 'Budget', 'active' => true, 'severity' => 'warning',
            'cooldown_minutes' => 60,
        ]);

        $now = Carbon::parse(self::TODAY.' 09:00:00');
        $first = app(AlertEvaluator::class)->evaluateRule($rule, $now);
        /*
         * FIVE DAYS later, not an hour.
         *
         * An hour proves nothing about this ledger: the alert engine's own cooldown would have
         * suppressed the second raise on its own, and the assertion would pass with the dedup here
         * deleted. Past the cooldown, the only thing standing between the customer and «80% used»
         * every sweep for the rest of the month is `spend_limit_events`.
         */
        $second = app(AlertEvaluator::class)->evaluateRule($rule, $now->copy()->addDays(5));

        $this->assertSame(1, $first, 'the crossing must raise exactly one alert');
        $this->assertSame(0, $second);

        /*
         * The count alone cannot see this, which is the point.
         *
         * `raise()` returns false both when it suppresses and when it REFRESHES an open event — and a
         * refresh re-notifies. So «0 raised» is true whether the same 80% is announced again or not,
         * and the observable difference is the timestamp: an untouched `last_triggered_at` means the
         * finding was never emitted a second time. Without the ledger check, this event would be
         * refreshed — and the customer re-notified — on every sweep for the rest of the month.
         */
        $alertAfter = AlertEvent::withoutGlobalScopes()->latest('last_triggered_at')->firstOrFail();
        $this->assertSame(
            $now->toDateTimeString(),
            $alertAfter->last_triggered_at?->toDateTimeString(),
            'the alert was refreshed and re-notified for a threshold that was already announced',
        );

        $event = SpendLimitEvent::withoutGlobalScopes()->where('spend_limit_id', $limit->id)->firstOrFail();
        $this->assertSame(80, $event->threshold);
        $this->assertEqualsWithDelta(8_500.0, $event->consumed, 0.01, 'the figures are kept as they stood, not recomputed later');
        $this->assertEqualsWithDelta(10_000.0, $event->limit_amount, 0.01);

        $alert = AlertEvent::withoutGlobalScopes()->latest('last_triggered_at')->firstOrFail();
        $this->assertSame('internal_monitoring', $alert->context['enforcement'] ?? null);
        $this->assertStringContainsString('does not stop delivery', (string) ($alert->context['message'] ?? '')
            .(string) ($alert->context['body'] ?? ''));
    }

    /** Spend nobody could convert is not a breach — it is the state where nothing can be said. */
    public function test_an_incomparable_limit_raises_nothing(): void
    {
        $this->spend(9_000, '2026-08-05');
        $this->limit(10_000, currency: 'EUR', thresholds: [80]);

        $rule = AlertRule::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'type' => 'budget_risk', 'name' => 'Budget', 'active' => true, 'severity' => 'warning',
        ]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule, Carbon::parse(self::TODAY)));
        $this->assertSame(0, SpendLimitEvent::withoutGlobalScopes()->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────────

    /** An owner of this tenant, with every permission — the reads and writes above need both. */
    private function owner(): array
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $user = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@limits.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return [$user];
    }

    /** @return array<string, mixed> */
    private function read(SpendLimit $limit): array
    {
        return app(SpendLimitGovernor::class)->read($limit, Carbon::parse(self::TODAY));
    }

    private function limit(
        float $amount,
        string $currency = 'USD',
        string $from = '2026-08-01',
        string $to = '2026-08-31',
        ?array $thresholds = null,
        SpendLimitScope $scope = SpendLimitScope::Project,
        ?string $scopeId = null,
    ): SpendLimit {
        return SpendLimit::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'scope' => $scope->value,
            'scope_id' => $scopeId,
            'amount' => $amount,
            'currency' => $currency,
            'starts_on' => $from,
            'ends_on' => $to,
            'thresholds' => $thresholds,
            'active' => true,
        ]);
    }

    /** @var array<string, ExternalAccount> one account per provider, reused across a test's rows */
    private array $accounts = [];

    /** The advertising account behind a day of spend — `daily_metrics.external_account_id` is NOT NULL. */
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
            'currency' => 'USD',
        ])->save();

        return $this->accounts[$provider] = $account;
    }

    /** @var array<string, ExternalCampaign> one campaign per provider — `external_campaign_id` is NOT NULL too */
    private array $campaigns = [];

    private function campaign(string $provider): ExternalCampaign
    {
        if (isset($this->campaigns[$provider])) {
            return $this->campaigns[$provider];
        }

        $account = $this->account($provider);
        $campaign = new ExternalCampaign;
        $campaign->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $provider,
            'external_id' => $provider.'-cmp',
            'name' => $provider.' campaign',
            'status' => 'active',
        ])->save();

        return $this->campaigns[$provider] = $campaign;
    }

    /** A converted day of spend, in the project's own reporting currency. */
    private function spend(float $amount, string $date, string $provider = 'meta'): void
    {
        $this->row($provider, $date, $amount, $amount, 'USD', 'USD');
    }

    /** A day the platform reported and no rate could convert — a real figure in another currency. */
    private function withheldSpend(float $original, string $date, string $currency, string $provider = 'meta'): void
    {
        $this->row($provider, $date, null, $original, $currency, 'USD');
    }

    private function row(string $provider, string $date, ?float $value, float $original, string $originalCurrency, string $projectCurrency): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account($provider)->id,
            'external_campaign_id' => $this->campaign($provider)->id,
            'provider' => $provider,
            'metric_key' => 'spend',
            'metric_date' => $date,
            'value' => $value,
            'original_amount' => $original,
            'original_currency' => $originalCurrency,
            'project_currency' => $projectCurrency,
            'exchange_rate' => $value === null ? null : 1,
        ]);
    }
}
