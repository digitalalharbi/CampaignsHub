<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

/**
 * GA4-ATTRIBUTION-MATCH-001 — the one canonical matcher: every ad-platform campaign against the GA4
 * traffic it produced, claimed beside measured.
 *
 * ## What this is, and what it is not
 *
 * Channel / campaign-level matching from GA4's OWN session attribution. It is NOT order-by-order
 * reconciliation with the store: that needs the Salla / Zid API the Owner declined, and nothing here
 * claims it (`order_level: false`, a «GA4» basis on every payload).
 *
 * ## Keys, strongest first
 *
 *   id       the platform's own campaign id is what GA4 recorded (`utm_id` / `sessionCampaignId` / Google
 *            Ads campaign id / a utm_campaign that IS the id), or the utm_campaign names the campaign AND
 *            sessions of that platform's click-id family (gclid, fbclid, ttclid, ScCid, ...) carried it
 *   utm      utm_campaign equals the campaign's name (normalised), unambiguously
 *   channel  GA4 saw the platform's visits (source / medium) but no campaign could be named: the
 *            platform's own «channel» bucket, never a campaign's
 *   unmatched  the platform has a campaign and no GA4 trace of it
 *
 * ## Rules that keep it honest
 *
 *   - a GA4 visit group is assigned to AT MOST ONE campaign: a name two campaigns claim, with nothing
 *     that says which platform, is ambiguous and goes to neither — one session is counted once;
 *   - a campaign can only claim visits whose source belongs to its own platform (or names none);
 *   - GA4 and platform figures are compared, never added (`summed: false`);
 *   - a figure with no honest denominator, or no USD rate, is null — never zero, never a guess.
 *
 * Pure arithmetic: callers pass scoped inputs, so a project can never see another project's rows here.
 */
final class Ga4AttributionMatcher
{
    public const CONFIDENCE = ['id', 'utm', 'channel', 'unmatched'];

    public function __construct(private readonly Ga4PlatformGap $gap) {}

    /**
     * @param  list<array{platform: string, external_id: string, name: string, claimed: array{conversions: ?float, revenue_usd: ?float, spend_usd: ?float, clicks: ?float}}>  $campaigns
     * @param  list<array{name: string, source: string, medium: string, sessions: float, purchases: float, revenue: float, revenue_usd: ?float}>  $units  GA4 session-campaign groups
     * @param  array{ids?: list<array{id: string, name: string}>, clicks?: list<array{family: string, campaign: string, sessions: float}>}  $evidence
     * @return array<string, mixed>
     */
    public function build(array $campaigns, array $units, array $evidence): array
    {
        $ids = [];
        foreach ($evidence['ids'] ?? [] as $row) {
            $ids[$this->norm($row['name'])][(string) $row['id']] = true;
        }
        $clicks = [];
        foreach ($evidence['clicks'] ?? [] as $row) {
            $clicks[$row['family']][$this->norm($row['campaign'])] = ($clicks[$row['family']][$this->norm($row['campaign'])] ?? 0.0) + $row['sessions'];
        }

        // Each unit's class: an ad platform, other_paid, or an unpaid class.
        foreach ($units as $i => $u) {
            $units[$i]['class'] = $this->gap->classOf($u['source'], $u['medium']);
            $units[$i]['norm'] = $this->norm($u['name']);
        }

        // Candidate claims: unit index => list of [campaign index, tier].
        $claims = [];
        foreach ($campaigns as $ci => $c) {
            foreach ($units as $ui => $u) {
                if (in_array($u['class'], Ga4PlatformGap::UNPAID, true)) {
                    continue; // organic / direct / referral / other can never be a platform's campaign
                }
                if ($u['class'] !== 'other_paid' && $u['class'] !== $c['platform']) {
                    continue; // the visit belongs to a different platform
                }
                $tier = $this->tier($c, $u, $ids);
                if ($tier !== null) {
                    $claims[$ui][] = [$ci, $tier];
                }
            }
        }

        // Resolve: the strongest tier wins; a tie between campaigns of different identity is ambiguous.
        $assigned = []; // unit index => [campaign index, tier]
        foreach ($claims as $ui => $list) {
            $best = in_array('id', array_column($list, 1), true) ? 'id' : 'utm';
            $winners = array_values(array_filter($list, static fn (array $x): bool => $x[1] === $best));
            if (count($winners) === 1) {
                $assigned[$ui] = $winners[0];
            }
        }

        $byCampaign = [];
        foreach ($assigned as $ui => [$ci, $tier]) {
            $byCampaign[$ci][] = [$ui, $tier];
        }

        $platforms = [];
        foreach ($campaigns as $ci => $c) {
            $platforms[$c['platform']]['campaigns'][] = $this->campaignRow($c, $ci, $byCampaign[$ci] ?? [], $units, $clicks);
        }

        // Platforms GA4 saw paid visits from, even with no campaign data on our side.
        foreach ($units as $u) {
            if ($u['class'] !== 'other_paid' && ! in_array($u['class'], Ga4PlatformGap::UNPAID, true)) {
                $platforms[$u['class']] ??= ['campaigns' => []];
            }
        }

        $usedUnits = array_keys($assigned);
        $otherPaid = null;
        $unpaid = [];
        $channel = [];
        foreach ($units as $ui => $u) {
            if (in_array($ui, $usedUnits, true)) {
                continue;
            }
            if ($u['class'] === 'other_paid') {
                $otherPaid = $this->add($otherPaid, $u);
            } elseif (in_array($u['class'], Ga4PlatformGap::UNPAID, true)) {
                $unpaid[$u['class']] = $this->add($unpaid[$u['class']] ?? null, $u);
            } else {
                $channel[$u['class']] = $this->add($channel[$u['class']] ?? null, $u);
            }
        }

        $rows = [];
        foreach ($platforms as $key => $p) {
            $camps = $p['campaigns'];
            $claimed = $this->claimTotals($camps);
            $matchedSessions = array_sum(array_map(static fn (array $c): float => (float) ($c['ga4']['sessions'] ?? 0), $camps));
            $classTotal = $this->totals($units, static fn (array $u): bool => $u['class'] === $key);
            $rows[] = [
                'platform' => $key,
                'claimed' => $camps === [] ? null : $claimed,
                'ga4' => $classTotal === null ? null : $this->shape($classTotal),
                'campaigns' => $camps,
                'channel' => isset($channel[$key]) ? ['name' => null, 'confidence' => 'channel', 'ga4' => $this->shape($channel[$key])] : null,
                'matched_share' => $classTotal !== null && $classTotal['sessions'] > 0 ? round($matchedSessions / $classTotal['sessions'], 4) : null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['platform'], $b['platform']));

        $unmatched = array_values(array_filter(
            array_merge(...array_map(static fn (array $p): array => $p['campaigns'], $rows ?: [['campaigns' => []]])),
            static fn (array $c): bool => $c['confidence'] === 'unmatched',
        ));
        $unattributedSessions = array_sum(array_map(static fn (array $t): float => $t['sessions'], $unpaid)) + ($otherPaid['sessions'] ?? 0.0);

        return [
            'basis' => 'ga4',
            'order_level' => false,
            'summed' => false,
            'model' => 'session_source_medium',
            'platforms' => $rows,
            'other_paid' => $otherPaid === null ? null : ['ga4' => $this->shape($otherPaid)],
            'unattributed' => [
                'sessions' => round($unattributedSessions, 2),
                'classes' => array_values(array_map(fn (string $class): array => ['class' => $class] + $this->shape($unpaid[$class]), array_values(array_filter(Ga4PlatformGap::UNPAID, static fn (string $k): bool => isset($unpaid[$k]))))),
            ],
            'unmatched' => [
                'campaigns' => count($unmatched),
                'spend_usd' => $unmatched === [] ? null : $this->sumNullable(array_map(static fn (array $c): ?float => $c['spend_usd'], $unmatched)),
                'clicks' => $unmatched === [] ? null : $this->sumNullable(array_map(static fn (array $c): ?float => $c['claimed']['clicks'] ?? null, $unmatched)),
            ],
        ];
    }

    /**
     * The client's reading: no spend, no cost, no ROAS and no campaign names — platform and source names only.
     *
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    public function forClient(array $match): array
    {
        $platforms = array_map(function (array $p): array {
            $matched = array_values(array_filter($p['campaigns'], static fn (array $c): bool => $c['confidence'] !== 'unmatched'));
            $claimed = $p['claimed'];
            if (is_array($claimed)) {
                unset($claimed['spend_usd'], $claimed['clicks']);
            }

            return [
                'platform' => $p['platform'],
                'claimed' => $claimed,
                'ga4' => $p['ga4'],
                'confidence' => $this->best(array_column($matched, 'confidence')),
                'matched_campaigns' => count($matched),
                'gap' => $this->claimGap($claimed, $p['ga4']),
                'matched_share' => $p['matched_share'],
            ];
        }, $match['platforms']);

        return [
            'basis' => $match['basis'], 'order_level' => false, 'summed' => false, 'model' => $match['model'],
            'platforms' => $platforms, 'other_paid' => $match['other_paid'], 'unattributed' => $match['unattributed'],
        ];
    }

    /** @param  list<string>  $confidences */
    private function best(array $confidences): string
    {
        foreach (['id', 'utm'] as $c) {
            if (in_array($c, $confidences, true)) {
                return $c;
            }
        }

        return 'channel';
    }

    /**
     * @param  array<string, mixed>|null  $claimed
     * @param  array<string, mixed>|null  $ga4
     * @return array<string, ?float>
     */
    private function claimGap(?array $claimed, ?array $ga4): array
    {
        return [
            'conversions' => $this->diff($claimed['conversions'] ?? null, $ga4['purchases'] ?? null),
            'conversions_share' => $this->share($claimed['conversions'] ?? null, $ga4['purchases'] ?? null),
            'revenue_usd' => $this->diff($claimed['revenue_usd'] ?? null, $ga4['revenue_usd'] ?? null),
            'revenue_share' => $this->share($claimed['revenue_usd'] ?? null, $ga4['revenue_usd'] ?? null),
        ];
    }

    /** @param  array<string, mixed>  $c */
    private function tier(array $c, array $u, array $ids): ?string
    {
        $extId = (string) $c['external_id'];
        if ($extId !== '' && ($u['name'] === $extId || isset($ids[$u['norm']][$extId]))) {
            return 'id';
        }
        if ($u['norm'] !== '' && $u['norm'] === $this->norm($c['name'])) {
            return 'utm';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  list<array{0: int, 1: string}>  $hits
     * @param  array<string, array<string, float>>  $clicks
     * @return array<string, mixed>
     */
    private function campaignRow(array $c, int $ci, array $hits, array $units, array $clicks): array
    {
        $claimed = $c['claimed'];
        $t = null;
        $tier = null;
        foreach ($hits as [$ui, $hitTier]) {
            $t = $this->add($t, $units[$ui]);
            $tier = $tier === 'id' || $hitTier === 'id' ? 'id' : 'utm';
            // A name match that GA4 sessions of the platform's own click-id family also carried is an id match.
            if ($hitTier === 'utm' && ($clicks[$c['platform']][$units[$ui]['norm']] ?? 0.0) > 0) {
                $tier = 'id';
            }
        }
        $ga4 = $t === null ? null : $this->shape($t);
        $spend = $claimed['spend_usd'] ?? null;
        $purchases = $ga4['purchases'] ?? null;

        return [
            'platform' => $c['platform'],
            'external_id' => $c['external_id'],
            'name' => $c['name'],
            'confidence' => $t === null ? 'unmatched' : $tier,
            'claimed' => $claimed,
            'ga4' => $ga4,
            'gap' => $this->claimGap($claimed, $ga4),
            'spend_usd' => $spend,
            'cost_per_purchase_usd' => $spend !== null && $purchases !== null && $purchases > 0 ? round($spend / $purchases, 2) : null,
            'roas_ga4' => $spend !== null && $spend > 0 && ($ga4['revenue_usd'] ?? null) !== null ? round($ga4['revenue_usd'] / $spend, 2) : null,
            'groups' => count($hits),
        ];
    }

    /** @param  list<array<string, mixed>>  $camps */
    private function claimTotals(array $camps): array
    {
        $col = static fn (string $k): array => array_map(static fn (array $c): ?float => $c['claimed'][$k] ?? null, $camps);

        return [
            'conversions' => $this->sumNullable($col('conversions')),
            'revenue_usd' => $this->sumNullable($col('revenue_usd')),
            'spend_usd' => $this->sumNullable($col('spend_usd')),
            'clicks' => $this->sumNullable($col('clicks')),
        ];
    }

    /** @param  list<?float>  $values  null when NO value is known, and when ANY is missing: a partial sum is not the sum */
    private function sumNullable(array $values): ?float
    {
        if ($values === [] || in_array(null, $values, true)) {
            return null;
        }

        return round(array_sum($values), 2);
    }

    /** @return array{sessions: float, purchases: float, revenue: float, revenue_usd: float, usd_complete: bool}|null */
    private function add(?array $acc, array $u): array
    {
        $acc ??= ['sessions' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0, 'revenue_usd' => 0.0, 'usd_complete' => true];
        $acc['sessions'] += $u['sessions'];
        $acc['purchases'] += $u['purchases'];
        $acc['revenue'] += $u['revenue'];
        if ($u['revenue_usd'] === null) {
            $acc['usd_complete'] = $acc['usd_complete'] && $u['revenue'] == 0.0;
        } else {
            $acc['revenue_usd'] += $u['revenue_usd'];
        }

        return $acc;
    }

    /** @return array{sessions: float, purchases: float, revenue: float, revenue_usd: float, usd_complete: bool}|null */
    private function totals(array $units, callable $pick): ?array
    {
        $acc = null;
        foreach ($units as $u) {
            if ($pick($u)) {
                $acc = $this->add($acc, $u);
            }
        }

        return $acc;
    }

    /**
     * @param  array{sessions: float, purchases: float, revenue: float, revenue_usd: float, usd_complete: bool}  $t
     * @return array<string, ?float>
     */
    private function shape(array $t): array
    {
        $usd = $t['usd_complete'] ? round($t['revenue_usd'], 2) : null;

        return [
            'sessions' => round($t['sessions'], 2),
            'purchases' => round($t['purchases'], 2),
            'revenue' => round($t['revenue'], 2),
            'revenue_usd' => $usd,
            'aov_usd' => $usd !== null && $t['purchases'] > 0 ? round($usd / $t['purchases'], 2) : null,
            'conversion_rate' => $t['sessions'] > 0 ? round($t['purchases'] / $t['sessions'], 4) : null,
        ];
    }

    private function diff(?float $platform, ?float $ga4): ?float
    {
        return $platform === null || $ga4 === null ? null : round($platform - $ga4, 2);
    }

    private function share(?float $platform, ?float $ga4): ?float
    {
        return $platform === null || $ga4 === null || $platform <= 0.0 ? null : round(($platform - $ga4) / $platform, 4);
    }

    /** One spelling of a campaign name: what a UTM template and a platform's own name have in common. */
    public function norm(string $name): string
    {
        $n = mb_strtolower(trim($name));

        return trim((string) preg_replace('/[\s_\-]+/u', '_', $n), '_');
    }
}
