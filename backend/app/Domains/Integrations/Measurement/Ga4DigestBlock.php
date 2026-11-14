<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Funding\Support\FundingMoney;
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
     * The line's pieces, so the mail can isolate every Latin fragment (a count, a dollar figure, a sign) as its own
     * left-to-right island inside a block whose direction is the locale's. One string with `dir="auto"` took the
     * direction of its first strong letter, the Latin «GA4», and an Arabic reader saw the order reversed.
     *
     * @param  array{sessions: ?float, purchases: ?float, revenue_usd: ?float, claimed: ?float, gap: ?float}|null  $b
     * @return list<array{value: string, label: string}>|null
     */
    public static function parts(?array $b, bool $ar): ?array
    {
        if ($b === null) {
            return null;
        }
        $n = static fn (float $v): string => number_format($v, 0, '.', ',');
        $parts = [];
        if ($b['sessions'] !== null) {
            $parts[] = ['value' => $n($b['sessions']), 'label' => $ar ? 'زيارة' : 'visits'];
        }
        if ($b['purchases'] !== null) {
            $parts[] = ['value' => $n($b['purchases']), 'label' => $ar ? 'شراء' : 'orders'];
        }
        if ($b['revenue_usd'] !== null) {
            $parts[] = ['value' => FundingMoney::withMark($n($b['revenue_usd']), 'USD'), 'label' => ''];
        }
        if ($b['gap'] !== null && $b['claimed'] !== null) {
            $parts[] = ['value' => ($b['gap'] > 0 ? '+' : ($b['gap'] < 0 ? '-' : '')).$n(abs($b['gap'])), 'label' => $ar ? 'فرق المنصات' : 'platform gap'];
        }

        return $parts === [] ? null : $parts;
    }

    /**
     * The line as plain text (logs, tests): the same pieces in reading order.
     *
     * @param  array{sessions: ?float, purchases: ?float, revenue_usd: ?float, claimed: ?float, gap: ?float}|null  $b
     */
    public static function line(?array $b, bool $ar): ?string
    {
        $parts = self::parts($b, $ar);
        if ($parts === null) {
            return null;
        }

        return ($ar ? 'الموقع' : 'Website').' · '.implode(' · ', array_map(static fn (array $p): string => $ar && $p['label'] !== '' && str_starts_with($p['label'], 'فرق') ? $p['label'].' '.$p['value'] : trim($p['value'].' '.$p['label']), $parts));
    }
}
