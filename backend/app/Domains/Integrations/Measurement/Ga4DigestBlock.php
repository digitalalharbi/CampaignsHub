<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * GA4-DIGEST-001 — the one compact GA4 line of a project's daily / weekly digest.
 *
 * Counts and USD only: visits, purchases, sales, the gap between what the platforms claimed and what GA4
 * measured. No spend, no cost, no ROAS, no campaign name — a digest reaches whatever inbox subscribed. A reader
 * without `analytics.store.view` gets the visits and nothing of the sales. Null when the project has no
 * selected property or nothing measured, and on any failure: GA4 absent must never break the digest.
 */
final class Ga4DigestBlock
{
    public function __construct(private readonly Ga4StoreAnalytics $store, private readonly Ga4GapExplain $gap, private readonly ProjectAbilities $abilities) {}

    /** @return array{sessions: ?float, purchases: ?float, revenue_usd: ?float, claimed: ?float, gap: ?float}|null */
    public function forProject(?User $user, string $tenantId, string $projectId, Carbon $from, Carbon $to): ?array
    {
        try {
            $r = $this->store->build($tenantId, $projectId, $from->copy()->startOfDay(), $to->copy()->startOfDay(), []);
            if (($r['state'] ?? null) !== 'ready') {
                return null;
            }
            $kpi = static function (string $key, string $field = 'current') use ($r): ?float {
                foreach ((array) $r['kpis'] as $k) {
                    if (($k['key'] ?? null) === $key) {
                        return $k[$field] === null ? null : (float) $k[$field];
                    }
                }

                return null;
            };
            $sales = $user === null || $this->abilities->allows($user, $projectId, ProjectCapability::ANALYTICS_STORE_VIEW);
            $out = ['sessions' => $kpi('sessions'), 'purchases' => null, 'revenue_usd' => null, 'claimed' => null, 'gap' => null];
            if ($sales) {
                $out['purchases'] = $kpi('transactions');
                $out['revenue_usd'] = $kpi('revenue', 'current_usd');
                $g = $this->gap->build($tenantId, $projectId, $from->copy()->startOfDay(), $to->copy()->startOfDay(), internal: false);
                if (($g['state'] ?? null) === 'ready') {
                    $out['claimed'] = $g['headline']['claimed'];
                    $out['gap'] = $g['headline']['gap'];
                }
            }

            return $out['sessions'] === null && $out['purchases'] === null ? null : $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The line the mail prints, AR or EN, Latin digits.
     *
     * @param  array{sessions: ?float, purchases: ?float, revenue_usd: ?float, claimed: ?float, gap: ?float}|null  $b
     */
    public static function line(?array $b, bool $ar): ?string
    {
        if ($b === null) {
            return null;
        }
        $n = static fn (float $v): string => number_format($v, 0, '.', ',');
        $parts = [];
        if ($b['sessions'] !== null) {
            $parts[] = $n($b['sessions']).($ar ? ' زيارة' : ' visits');
        }
        if ($b['purchases'] !== null) {
            $parts[] = $n($b['purchases']).($ar ? ' شراء' : ' orders');
        }
        if ($b['revenue_usd'] !== null) {
            $parts[] = '$'.$n($b['revenue_usd']);
        }
        if ($b['gap'] !== null && $b['claimed'] !== null) {
            $parts[] = ($ar ? 'فرق المنصات ' : 'platform gap ').($b['gap'] > 0 ? '+' : ($b['gap'] < 0 ? '−' : '')).$n(abs($b['gap']));
        }

        return $parts === [] ? null : 'GA4 · '.implode(' · ', $parts);
    }
}
