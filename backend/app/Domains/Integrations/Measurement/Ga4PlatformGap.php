<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

/**
 * GA4-PLATFORM-GAP-001 — «GA4 vs platforms»: what each ad platform reported beside what GA4 measured
 * for the visits that platform sent, and the difference between the two as a figure and a share.
 *
 * Pure arithmetic over two readings the caller already scoped. Rules it obeys:
 *   - the two are COMPARED, never added: nothing here sums a platform figure with a GA4 figure, and
 *     the payload says `summed: false`;
 *   - one mapper decides whose visit a session was: `Ga4SiteAnalytics::platformOf()` for paid
 *     visits (medium says paid, source names the platform), and the medium for everything else;
 *   - the difference is `platform − GA4`, its share is of the PLATFORM's own figure, and it is
 *     withheld (null) when either side is absent or the platform reported nothing to divide by;
 *   - money is compared in USD only when BOTH sides converted; otherwise the money gap is withheld
 *     rather than computed across two currencies.
 */
final class Ga4PlatformGap
{
    /** The traffic GA4 measured that no ad platform paid for, in reading order. */
    public const UNPAID = ['organic', 'direct', 'referral', 'other'];

    public function __construct(private readonly Ga4SiteAnalytics $site) {}

    /**
     * Whose visit a source / medium pair was: an ad platform key, `other_paid`, or one of UNPAID.
     */
    public function classOf(string $source, string $medium): string
    {
        $platform = $this->site->platformOf($source, $medium);
        if ($platform !== null) {
            return $platform;
        }

        $medium = strtolower(trim($medium));
        $source = strtolower(trim($source));

        return match (true) {
            $medium === 'organic' => 'organic',
            $source === '(direct)' && in_array($medium, ['(none)', '(not set)', ''], true) => 'direct',
            $medium === 'referral' => 'referral',
            default => 'other',
        };
    }

    /**
     * @param  array<string, array{conversions: ?float, revenue: ?float, currency: ?string, revenue_usd: ?float}>  $reported  per platform, as the platform reported it
     * @param  list<array{source: string, medium: string, sessions: float, purchases: float, revenue: float, revenue_usd: ?float}>  $visits  GA4 source / medium rows for the same window
     * @return array<string, mixed>
     */
    public function build(array $reported, array $visits, ?string $ga4Currency): array
    {
        $measured = [];
        $total = 0.0;

        foreach ($visits as $v) {
            $class = $this->classOf($v['source'], $v['medium']);
            $measured[$class] ??= ['sessions' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0, 'revenue_usd' => 0.0, 'usd_complete' => true];
            $measured[$class]['sessions'] += $v['sessions'];
            $measured[$class]['purchases'] += $v['purchases'];
            $measured[$class]['revenue'] += $v['revenue'];
            if ($v['revenue_usd'] === null) {
                // One day without a rate withholds the USD figure for the whole class: a partial sum is not the sum.
                $measured[$class]['usd_complete'] = $measured[$class]['usd_complete'] && $v['revenue'] == 0.0;
            } else {
                $measured[$class]['revenue_usd'] += $v['revenue_usd'];
            }
            $total += $v['sessions'];
        }

        $ga4 = static function (?array $m) use ($ga4Currency, $total): ?array {
            if ($m === null) {
                return null;
            }

            return [
                'sessions' => round($m['sessions'], 2),
                'purchases' => round($m['purchases'], 2),
                'revenue' => round($m['revenue'], 2),
                'currency' => $ga4Currency,
                'revenue_usd' => $m['usd_complete'] ? round($m['revenue_usd'], 2) : null,
                'session_share' => $total > 0 ? round($m['sessions'] / $total, 4) : null,
            ];
        };

        $rows = [];
        $platforms = array_values(array_unique([
            ...array_keys($reported),
            ...array_values(array_filter(array_keys($measured), static fn (string $k): bool => $k !== 'other_paid' && ! in_array($k, self::UNPAID, true))),
        ]));
        sort($platforms);

        foreach ($platforms as $platform) {
            $claim = $reported[$platform] ?? null;
            // A property that is read but saw no visit from this platform measured ZERO for it, which is a figure.
            $site = $ga4($measured[$platform] ?? ($visits === [] ? null : ['sessions' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0, 'revenue_usd' => 0.0, 'usd_complete' => true]));

            $rows[] = [
                'platform' => $platform,
                'reported' => $claim === null ? null : [
                    'conversions' => $claim['conversions'] === null ? null : round($claim['conversions'], 2),
                    'revenue' => $claim['revenue'] === null ? null : round($claim['revenue'], 2),
                    'currency' => $claim['currency'],
                    'revenue_usd' => $claim['revenue_usd'] === null ? null : round($claim['revenue_usd'], 2),
                ],
                'ga4' => $site,
                'gap' => [
                    'conversions' => $this->difference($claim['conversions'] ?? null, $site['purchases'] ?? null),
                    'conversions_share' => $this->share($claim['conversions'] ?? null, $site['purchases'] ?? null),
                    'revenue_usd' => $this->difference($claim['revenue_usd'] ?? null, $site['revenue_usd'] ?? null),
                    'revenue_share' => $this->share($claim['revenue_usd'] ?? null, $site['revenue_usd'] ?? null),
                ],
            ];
        }

        $unpaid = [];
        foreach (self::UNPAID as $class) {
            if (isset($measured[$class])) {
                $unpaid[] = ['class' => $class] + (array) $ga4($measured[$class]);
            }
        }

        return [
            'summed' => false,
            'rows' => $rows,
            'unpaid' => $unpaid,
            'other_paid' => isset($measured['other_paid']) ? $ga4($measured['other_paid']) : null,
            'ga4_sessions' => $visits === [] ? null : round($total, 2),
        ];
    }

    private function difference(?float $platform, ?float $ga4): ?float
    {
        return $platform === null || $ga4 === null ? null : round($platform - $ga4, 2);
    }

    /** The difference as a share of what the platform itself reported; withheld when it reported nothing. */
    private function share(?float $platform, ?float $ga4): ?float
    {
        return $platform === null || $ga4 === null || $platform <= 0.0 ? null : round(($platform - $ga4) / $platform, 4);
    }
}
