<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Support;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Support\ObjectiveResult;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Domains\Projects\Concerns\ProjectScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ALERT-DELIVERING-YESTERDAY-001 — which campaigns a performance alert or a digest decision may judge at all, and what was
 * proven on the way (OWNER_NOTIFICATIONS_RULES_2026-11-12 §2, §6 and §10أ checks 1–3).
 *
 * «كرم النخيل» kept receiving «صرف بدون نتائج» about Snapchat campaigns stopped since 10 Nov. Two holes let them through:
 * our row still said `active` (adopted from the platform, never refreshed) while the platform said `paused`; and «the last
 * N days» on the server's clock still reached the spend from before the pause.
 *
 * ## The checks, in the Owner's order — the first that holds decides, and every one is recorded in `checks`
 *
 * 1. **Running now** (`running`) — by the EFFECTIVE status (below). Not running → `campaign_inactive`.
 * 2. **Delivering, and since when** (`active_since`) — spend or impressions YESTERDAY on the client's clock (or on the
 *    caller's `$lastDay`), else `not_delivering` — but only once a successful metrics sync of every linked ad account covered
 *    yesterday; before that, `data_pending` (no verdict, nothing closed). `active_since` is the first day of the unbroken run of delivering days that
 *    ends yesterday (looked up to {@see LOOKBACK_DAYS} back); a day with neither spend nor impressions is a pause.
 *    **Learning / minimum sample** — the judged run (from `active_since`, capped to the rule's window) must reach the
 *    objective family's minimum spend (its minimum impressions where no spend could be stated) ({@see MIN_SAMPLE}, overridable per rule as `threshold.min_spend` /
 *    `threshold.min_impressions`), else `learning`. A platform's own learning-phase flag is not fetched: recorded `not_available`.
 * 3. **Reached its end** — end date passed (ours, or the platform's `ends_at`) → `campaign_ended`; lifetime budget spent per
 *    the platform's budget and our stored spend in the SAME currency → `budget_exhausted` (the action is a renewal, not
 *    «weak performance»). A lifetime budget in another currency than the stored spend cannot be compared: `not_available`.
 *    A daily budget fully spent yesterday is recorded (`daily_budget_reached`) but does not withhold a verdict: it resumes the
 *    next day by itself.
 * 4. **Setup** (for a running campaign that spent nothing yesterday) — from what the structure sync stores: every ad rejected or
 *    in review (proof nothing can deliver), and a bid or cost cap on an ad set (a fact, never named «the cause»). The audience's
 *    size is not fetched: `not_available`.
 * 5. **Payment / funding** and the platforms' learning flag are not fetched today: `not_available`, never guessed.
 *
 * `last_delivered` and, for a campaign no longer running, `stopped_yesterday` (delivered the day before yesterday, not
 * yesterday, and its platform says it is not running) are recorded too: «توقفت أمس» is said once, from that.
 *
 * Eligible campaigns carry `from`–`to`: the judgement window, the run since `active_since` capped to the rule's window, so spend
 * from before a pause or a restart is never counted.
 *
 * ## The effective status
 *
 * The linked platform campaign's status, when it is known (not `unknown`) and either the unified campaign was adopted by a sync
 * (its own word was never refreshed — PR #1020 makes the sync follow the platform; until then, and for any status it does not
 * map, this reads the platform directly) or the platform was synced after our row last changed. A status a person set after the
 * last sync (`meta.status_source = manual`, #1020) stays theirs. Several platform campaigns behind one: running when any runs.
 */
final class CampaignDelivery
{
    public const INACTIVE = 'campaign_inactive';

    public const ENDED = 'campaign_ended';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    public const NOT_DELIVERING = 'not_delivering';

    public const LEARNING = 'learning';

    /**
     * Yesterday's figures are not proven absent: a linked ad account has no successful metrics sync covering yesterday. A late or
     * failed sync is not a pause — the verdict is withheld and an open alert is LEFT as it is (neither closed nor «تعافى»).
     */
    public const DATA_PENDING = 'data_pending';

    /** How far back `active_since` is looked for. */
    public const LOOKBACK_DAYS = 60;

    /**
     * The smallest judged run a verdict may rest on, per objective family: spend in the project's currency, impressions.
     * Starting values pending the Owner's tuning; a rule overrides them with `threshold.min_spend` / `threshold.min_impressions`.
     *
     * @var array<string, array{spend: float, impressions: int}>
     */
    public const MIN_SAMPLE = [
        'sales' => ['spend' => 50.0, 'impressions' => 1000],
        'leads' => ['spend' => 50.0, 'impressions' => 1000],
        'app' => ['spend' => 30.0, 'impressions' => 1000],
        'traffic' => ['spend' => 20.0, 'impressions' => 500],
        'engagement' => ['spend' => 20.0, 'impressions' => 500],
        'video' => ['spend' => 20.0, 'impressions' => 500],
        'awareness' => ['spend' => 20.0, 'impressions' => 500],
        'unknown' => ['spend' => 30.0, 'impressions' => 1000],
    ];

    /** @var array<string, array{platform: array<string, list<array<string, mixed>>>, series: array<string, list<array<string, mixed>>>, syncs: array<string, list<array{0: string, 1: string}>>, setup: array<string, array<string, mixed>>}> */
    private array $memo = [];

    public function __construct(
        private readonly MetricsAggregator $metrics,
        private readonly ReportingTimezone $clock,
    ) {}

    /** Forget what this instance read: a sweep reads once per campaign set and clock, never across sweeps. */
    public function forget(): void
    {
        $this->memo = [];
        $this->cards = [];
    }

    /** @var array<string, array{providers: list<string>, accounts: list<string>, ad_set: ?string}> */
    private array $cards = [];

    /**
     * What an alert card names for a campaign this instance assessed — read with its delivery, so a sweep raising many alerts
     * costs no statement per alert.
     *
     * @return array{providers: list<string>, accounts: list<string>, ad_set: ?string}
     */
    public function cardFacts(string $campaignId): array
    {
        return $this->cards[$campaignId] ?? ['providers' => [], 'accounts' => [], 'ad_set' => null];
    }

    /**
     * `$lastDay` names the measured period's last day for a caller that is not measuring «yesterday» (a weekly digest's last
     * day); without it, the last day is yesterday on each project's own clock.
     *
     * @param  iterable<UnifiedCampaign>  $campaigns
     * @param  array{min_spend?: mixed, min_impressions?: mixed}  $sample  per-rule overrides of {@see MIN_SAMPLE}
     * @return array<string, array{reason: ?string, from: ?string, to: string, days: int, status: string, active_since: ?string, checks: array<string, mixed>}>
     */
    public function assess(iterable $campaigns, Carbon $now, int $windowDays, ?string $lastDay = null, array $sample = []): array
    {
        $campaigns = Collection::make($campaigns)->values();
        if ($campaigns->isEmpty()) {
            return [];
        }
        $windowDays = max(1, $windowDays);

        // The client's yesterday, per project (a tenant-wide rule may span zones).
        $days = [];
        foreach ($campaigns as $c) {
            $pid = (string) $c->project_id;
            if (! isset($days[$pid])) {
                $zone = $this->clock->forProject($pid);
                $today = $lastDay !== null
                    ? Carbon::parse($lastDay)->addDay()->startOfDay()
                    : $now->copy()->setTimezone($zone)->startOfDay();
                $days[$pid] = ['zone' => $zone, 'today' => $today->toDateString(), 'yesterday' => $today->copy()->subDay()->toDateString(),
                    'start' => $today->copy()->subDays($windowDays)->toDateString()];
            }
        }

        $ids = $campaigns->map(static fn (UnifiedCampaign $c): string => (string) $c->getKey())->sort()->values()->all();
        $to = Carbon::parse(max(array_column($days, 'yesterday')));
        $read = $this->read($ids, Carbon::parse(min(array_column($days, 'yesterday')))->subDays(self::LOOKBACK_DAYS), $to);

        $out = [];
        foreach ($campaigns as $c) {
            $id = (string) $c->getKey();
            $out[$id] = $this->verdict($c, $days[(string) $c->project_id], $read['platform'][$id] ?? [], $read['series'][$id] ?? [], $read['syncs'], $read['setup'], $sample);
        }

        return $out;
    }

    /**
     * @param  array{zone: string, today: string, yesterday: string, start: string}  $d
     * @param  list<array<string, mixed>>  $platform
     * @param  list<array<string, mixed>>  $series
     * @param  array<string, list<array{0: string, 1: string}>>  $syncs  account → successful sync windows
     * @param  array<string, array<string, mixed>>  $setup  campaign → what its stored ads and ad sets say ({@see setupFacts()})
     * @param  array{min_spend?: mixed, min_impressions?: mixed}  $sample
     * @return array{reason: ?string, from: ?string, to: string, days: int, status: string, active_since: ?string, checks: array<string, mixed>}
     */
    private function verdict(UnifiedCampaign $c, array $d, array $platform, array $series, array $syncs, array $setup, array $sample): array
    {
        $status = $this->effectiveStatus($c, $platform);
        $checks = [
            'running' => ['status' => $status, 'stored' => (string) $c->status, 'platform' => array_values(array_unique(array_column($platform, 'status')))],
            'learning_phase' => 'not_available',
            'setup' => 'not_available',
            'payment' => 'not_available',
        ];
        $base = ['from' => null, 'to' => $d['yesterday'], 'days' => 0, 'status' => $status, 'active_since' => null];
        // By reference: each check below adds what it proved before the verdict is taken.
        $decide = static function (?string $reason, array $over = []) use ($base, &$checks): array {
            return array_merge($base, $over, ['reason' => $reason, 'checks' => $checks]);
        };

        $byDay = [];
        foreach ($series as $point) {
            $byDay[(string) $point['date']] = $point;
        }
        $delivered = static fn (string $day): bool => isset($byDay[$day]) && ((float) ($byDay[$day]['spend'] ?? 0) > 0 || (float) ($byDay[$day]['impressions'] ?? 0) > 0);
        $last = null;
        foreach (array_keys($byDay) as $day) {
            if ($day <= $d['yesterday'] && $delivered($day) && ($last === null || $day > $last)) {
                $last = $day;
            }
        }
        $checks['last_delivered'] = $last;

        // 1. Running now.
        if ($status !== CampaignStatus::Active->value) {
            /*
             * §6 — the one paused-related message: «توقفت أمس», once. Proven by the platform's own status (not running now) and the
             * figures (it delivered the day before yesterday and not yesterday). The next day the last delivery is older, so it is
             * never said twice.
             */
            // The platform itself must say it is not running (not our row), and yesterday's figures must have arrived for every
            // linked account — otherwise «last delivered the day before yesterday» is only «yesterday's figures are late».
            $known = array_values(array_filter($platform, static fn (array $p): bool => ! in_array($p['status'], ['', CampaignStatus::Unknown->value], true)));
            $platformStopped = $known !== [] && ! in_array(CampaignStatus::Active->value, array_column($known, 'status'), true);
            $arrived = $platform !== [] && array_filter($platform, fn (array $p): bool => $p['account'] === '' || ! $this->covered($syncs[$p['account']] ?? [], $d['yesterday'])) === [];
            $checks['stopped_yesterday'] = $last !== null && $last === Carbon::parse($d['yesterday'])->subDay()->toDateString() && $platformStopped && $arrived;

            return $decide(self::INACTIVE);
        }

        // 3a. End date passed — ours, or the platform's (its own clock is the client's: `ends_at` is read in the project's zone).
        $ends = $c->ends_on?->toDateString();
        foreach ($platform as $p) {
            if ($p['ends_at'] instanceof Carbon) {
                $pe = $p['ends_at']->copy()->setTimezone($d['zone'])->toDateString();
                $ends = $ends === null ? $pe : min($ends, $pe);
            }
        }
        $checks['ends_on'] = $ends;
        if ($ends !== null && $ends < $d['today']) {
            return $decide(self::ENDED);
        }

        // 2. Delivering yesterday, and since when.
        if (! $delivered($d['yesterday'])) {
            foreach ($platform as $p) {
                if ($p['account'] !== '' && ! $this->covered($syncs[$p['account']] ?? [], $d['yesterday'])) {
                    $checks['delivered_yesterday'] = 'not_proven';

                    return $decide(self::DATA_PENDING);
                }
            }
            $checks['delivered_yesterday'] = false;
            // §10أ check 4 — running, its figures arrived, and it spent nothing: what its stored setup says about why.
            $checks['setup'] = $setup[(string) $c->getKey()] ?? ['ads' => null, 'bid_limit' => null, 'audience' => 'not_available'];

            return $decide(self::NOT_DELIVERING);
        }
        $checks['delivered_yesterday'] = true;
        $since = Carbon::parse($d['yesterday']);
        while ($delivered($since->copy()->subDay()->toDateString())) {
            $since->subDay();
        }
        $activeSince = $since->toDateString();
        $from = max($activeSince, $d['start']);
        $checks['active_since'] = $activeSince;

        // 3b. Budgets, as the platform states them, against our stored spend in the same currency (spend to date = the lookback's).
        if (count($platform) > 1 && array_filter($platform, static fn (array $p): bool => $p['lifetime_budget'] > 0) !== []) {
            $checks['lifetime_budget'] = 'not_available';
        }
        $yesterday = (float) ($byDay[$d['yesterday']]['spend'] ?? 0);
        $toDate = 0.0;
        foreach ($series as $point) {
            $toDate += (float) ($point['spend'] ?? 0);
        }
        // The stored series is the campaign's, not each platform's: a budget compares only when one platform campaign stands behind it.
        foreach (count($platform) === 1 ? $platform : [] as $p) {
            if ((float) $p['daily_budget'] > 0 && $yesterday >= 0.95 * (float) $p['daily_budget'] && $this->sameCurrency($p)) {
                $checks['daily_budget_reached'] = true;
            }
            if ((float) $p['lifetime_budget'] > 0) {
                if (! $this->sameCurrency($p)) {
                    $checks['lifetime_budget'] = 'not_available';
                } elseif ($toDate >= (float) $p['lifetime_budget']) {
                    $checks['lifetime_budget'] = 'reached';

                    return $decide(self::BUDGET_EXHAUSTED, ['active_since' => $activeSince]);
                } else {
                    $checks['lifetime_budget'] = 'remaining';
                }
            }
        }

        // 2b. Learning / minimum sample over the judged run.
        $spend = 0.0;
        $impressions = 0.0;
        for ($day = Carbon::parse($from); $day->toDateString() <= $d['yesterday']; $day->addDay()) {
            $spend += (float) ($byDay[$day->toDateString()]['spend'] ?? 0);
            $impressions += (float) ($byDay[$day->toDateString()]['impressions'] ?? 0);
        }
        $family = ObjectiveResult::familyOf($c->objective === null ? null : (string) $c->objective)->value;
        $min = self::MIN_SAMPLE[$family] ?? self::MIN_SAMPLE['unknown'];
        $minSpend = is_numeric($sample['min_spend'] ?? null) ? (float) $sample['min_spend'] : $min['spend'];
        $minImpressions = is_numeric($sample['min_impressions'] ?? null) ? (float) $sample['min_impressions'] : (float) $min['impressions'];
        $checks['sample'] = ['spend' => round($spend, 2), 'impressions' => (int) $impressions, 'min_spend' => $minSpend, 'min_impressions' => (int) $minImpressions];
        $over = ['from' => $from, 'days' => (int) Carbon::parse($from)->diffInDays(Carbon::parse($d['yesterday'])) + 1, 'active_since' => $activeSince];
        // Spend decides; impressions stand in only where the spend could not be stated (an unconverted currency reads 0 here).
        if ($spend > 0 ? $spend < $minSpend : $impressions < $minImpressions) {
            return $decide(self::LEARNING, $over);
        }

        return $decide(null, $over);
    }

    /**
     * Bid strategies that cap what the platform may pay — a setting that can hold delivery back, stated as a fact, never as «the
     * cause». The values are the platforms' own enums as the connectors store them (lower-cased):
     * - Meta ad set `bid_strategy` (Marketing API, Ad Set reference): LOWEST_COST_WITH_BID_CAP, COST_CAP, LOWEST_COST_WITH_MIN_ROAS.
     * - Snapchat ad squad `bid_strategy` (Marketing API, Ad Squads): LOWEST_COST_WITH_MAX_BID, TARGET_COST, MIN_ROAS.
     * - TikTok ad group `bid_type` (Business API, Ad Group): BID_TYPE_CUSTOM (a bid or cost cap).
     * - Google ad group: a target CPA (`targetCpaMicros`, stored as `target_cpa`).
     * - X line item `bid_strategy` (Ads API, Line Items): MAX, TARGET.
     */
    public const BID_LIMITS = ['lowest_cost_with_bid_cap', 'cost_cap', 'lowest_cost_with_min_roas', 'lowest_cost_with_max_bid', 'target_cost', 'min_roas', 'bid_type_custom', 'target_cpa', 'max', 'target'];

    /**
     * §10أ check 4, from what the structure sync already stores: the ads' review verdicts (`external_ads.review_status`, each
     * connector's own mapping of its platform's review answer to approved / pending / rejected) and the ad sets' bid strategies.
     * `ads` is `rejected` or `in_review` only when no ad of the campaign is approved and at least one carries that answer — then
     * nothing of it can deliver, which is proof. The audience's size is not fetched: `not_available`.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function setupFacts(array $ids): array
    {
        $out = [];
        ExternalAd::query()->withoutGlobalScope(ProjectScope::class)
            ->whereIn('unified_campaign_id', $ids)
            ->toBase()->get(['unified_campaign_id', 'review_status'])
            ->groupBy('unified_campaign_id')
            ->each(static function ($ads, $cid) use (&$out): void {
                // Proof only when EVERY ad carries a review answer that stops it: all rejected → «rejected»; all rejected or
                // pending with one pending → «in_review». An ad with no answer (Meta's PAUSED, say) or an approved one: no claim.
                $answers = $ads->pluck('review_status')->map(static fn ($v): string => strtolower((string) $v))->all();
                $out[(string) $cid]['ads'] = array_diff($answers, ['rejected']) === [] ? 'rejected'
                    : (array_diff($answers, ['rejected', 'pending']) === [] ? 'in_review' : null);
            });
        ExternalAdSet::query()->withoutGlobalScope(ProjectScope::class)
            ->whereIn('unified_campaign_id', $ids)
            ->whereIn('bid_strategy', self::BID_LIMITS)
            ->where('status', 'active')
            ->toBase()->get(['unified_campaign_id', 'bid_strategy'])
            ->each(static function ($set) use (&$out): void {
                $out[(string) $set->unified_campaign_id]['bid_limit'] = (string) $set->bid_strategy;
            });
        foreach ($out as $cid => $facts) {
            $out[$cid] = $facts + ['ads' => null, 'bid_limit' => null, 'audience' => 'not_available'];
        }

        return $out;
    }

    /** @param  list<array{0: string, 1: string}>  $windows */
    private function covered(array $windows, string $day): bool
    {
        foreach ($windows as [$from, $to]) {
            if ($from <= $day && $day <= $to) {
                return true;
            }
        }

        return false;
    }

    /** Stored spend is in the project's currency; a platform budget compares only when it is stated in that same currency. */
    /** @param  array<string, mixed>  $platform */
    private function sameCurrency(array $platform): bool
    {
        $currency = strtoupper((string) ($platform['currency'] ?? ''));

        return $currency !== '' && $currency === strtoupper((string) ($platform['spend_currency'] ?? ''));
    }

    /**
     * The campaign's status by the fresher of our row and its platform's last sync.
     *
     * @param  list<array<string, mixed>>  $platform
     */
    public function effectiveStatus(UnifiedCampaign $c, array $platform): string
    {
        $stored = (string) $c->status;
        $known = array_values(array_filter($platform, static fn (array $p): bool => ! in_array($p['status'], ['', CampaignStatus::Unknown->value], true)));
        if ($known === []) {
            return $stored;
        }

        $meta = (array) ($c->meta ?? []);
        $manual = ($meta['status_source'] ?? null) === 'manual';
        $adopted = ! $manual && is_string($meta['adopted_from'] ?? null) && $meta['adopted_from'] !== '';
        $syncedAt = null;
        foreach ($known as $p) {
            if ($p['synced_at'] instanceof Carbon && ($syncedAt === null || $p['synced_at']->greaterThan($syncedAt))) {
                $syncedAt = $p['synced_at'];
            }
        }
        $fresher = $syncedAt !== null && ($c->updated_at === null || $syncedAt->greaterThanOrEqualTo($c->updated_at));
        if (! $adopted && ! $fresher) {
            return $stored;
        }

        $statuses = array_column($known, 'status');

        return in_array(CampaignStatus::Active->value, $statuses, true) ? CampaignStatus::Active->value : (string) $statuses[0];
    }

    /**
     * The platform campaigns and the daily series for a campaign set, read once per set and clock.
     *
     * @param  list<string>  $ids
     * @return array{platform: array<string, list<array<string, mixed>>>, series: array<string, list<array<string, mixed>>>, syncs: array<string, list<array{0: string, 1: string}>>, setup: array<string, array<string, mixed>>}
     */
    private function read(array $ids, Carbon $from, Carbon $to): array
    {
        $key = sha1(implode(',', $ids).'|'.$from->toDateString().'|'.$to->toDateString());
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $platform = [];
        ExternalCampaign::query()->withoutGlobalScope(ProjectScope::class)
            ->whereIn('unified_campaign_id', $ids)
            ->whereNull('unlinked_at')
            ->get(['unified_campaign_id', 'external_account_id', 'provider', 'status', 'last_synced_at', 'ends_at', 'daily_budget', 'lifetime_budget', 'currency'])
            ->each(static function (ExternalCampaign $e) use (&$platform): void {
                $platform[(string) $e->unified_campaign_id][] = [
                    'status' => strtolower((string) $e->status),
                    'account' => (string) ($e->external_account_id ?? ''),
                    'provider' => (string) $e->provider,
                    // Only a sync proves what the platform said and when; a row never synced proves nothing about freshness.
                    'synced_at' => $e->last_synced_at,
                    'ends_at' => $e->ends_at,
                    'daily_budget' => (float) ($e->daily_budget ?? 0),
                    'lifetime_budget' => (float) ($e->lifetime_budget ?? 0),
                    'currency' => (string) ($e->currency ?? ''),
                ];
            });

        $scoped = $this->metrics->acrossProjects()->forCampaigns($ids);
        $series = $scoped->timeseriesByCampaign($from, $to);

        // The currency our stored spend is in, per campaign: a budget compares only against spend in its own currency.
        $budgeted = array_keys(array_filter($platform, static fn (array $rows): bool => array_filter($rows, static fn (array $p): bool => $p['daily_budget'] > 0 || $p['lifetime_budget'] > 0) !== []));
        if ($budgeted !== []) {
            /*
             * Read through the aggregator (the one visibility rule applies). One read for every budgeted campaign: when their stored
             * spend agrees on one currency, that is each one's; when it mixes, no budget comparison is stated (`not_available`).
             */
            $basis = $this->metrics->acrossProjects()->forCampaigns($budgeted)->currencyBasis($from, $to);
            $unit = $basis['bases'] === 1 ? (string) $basis['currency'] : '';
            foreach ($budgeted as $cid) {
                foreach ($platform[$cid] as $i => $p) {
                    $platform[$cid][$i]['spend_currency'] = $unit;
                }
            }
        }

        // Which days each linked ad account's metrics sync covered successfully — the proof that an empty day is really empty.
        $accounts = [];
        foreach ($platform as $rows) {
            foreach ($rows as $p) {
                if ($p['account'] !== '') {
                    $accounts[$p['account']] = true;
                }
            }
        }
        $syncs = [];
        if ($accounts !== []) {
            MetricSyncRun::query()->withoutGlobalScope(ProjectScope::class)
                ->whereIn('external_account_id', array_keys($accounts))
                ->where('status', 'success')
                ->where('window_end', '>=', $to->copy()->subDays(self::LOOKBACK_DAYS)->toDateString())
                ->toBase()->get(['external_account_id', 'window_start', 'window_end'])
                ->each(static function ($r) use (&$syncs): void {
                    $syncs[(string) $r->external_account_id][] = [Carbon::parse((string) $r->window_start)->toDateString(), Carbon::parse((string) $r->window_end)->toDateString()];
                });
        }

        // What an alert card names (§10أ): the platforms, the ad accounts' names, and the ad set when the campaign has exactly one.
        $names = $accounts === [] ? [] : ExternalAccount::query()->withoutGlobalScopes()->whereIn('id', array_keys($accounts))->pluck('name', 'id')->all();
        $sets = [];
        ExternalAdSet::query()->withoutGlobalScope(ProjectScope::class)->whereIn('unified_campaign_id', $ids)
            ->toBase()->get(['unified_campaign_id', 'name'])
            ->each(static function ($r) use (&$sets): void {
                $sets[(string) $r->unified_campaign_id][] = (string) $r->name;
            });
        foreach ($ids as $cid) {
            $rows = $platform[$cid] ?? [];
            $this->cards[$cid] = [
                'providers' => array_values(array_unique(array_column($rows, 'provider'))),
                'accounts' => array_values(array_unique(array_filter(array_map(static fn (array $p): string => (string) ($names[$p['account']] ?? ''), $rows)))),
                'ad_set' => count($sets[$cid] ?? []) === 1 ? $sets[$cid][0] : null,
            ];
        }

        return $this->memo[$key] = ['platform' => $platform, 'series' => $series, 'syncs' => $syncs, 'setup' => $this->setupFacts($ids)];
    }
}
