<?php

declare(strict_types=1);

namespace App\Domains\Reports\Measurement;

use App\Domains\Metrics\Audience\AudienceCenter;
use Illuminate\Support\Carbon;

/**
 * AUDIENCE-001 — the audience reading as report sections, each data type a SHOW/HIDE of its own.
 *
 *   audience_targeting    the ad sets' TARGETING settings (incl. geo) — a setting, never delivery
 *   audience_regions      paid delivery by region / country
 *   audience_cities       paid delivery by city
 *   audience_gender       paid delivery by gender
 *   audience_age          paid delivery by age
 *   audience_devices      paid delivery by device
 *   audience_placements   paid delivery by placement
 *   audience_interests    paid delivery by interest
 *   audience_professional LinkedIn professional facets
 *   audience_site         the SITE's visitors by country / device (GA4) — never the ads' audience
 *   audience_spend        a modifier, no payload of its own: when hidden, no money in any audience part
 *
 * Decided here, on the server, before anything is serialised: a hidden part is null (and the section
 * system removes its key), a client-facing report carries no money and no campaign / ad-set names, and
 * the link's account ceiling bounds every part, and a link that names specific campaigns, ad sets or ads
 * carries no audience part (narrowing the breakdowns by campaign is not built, so they are withheld rather
 * than leaked).
 */
final class AudienceReportSections
{
    public const SPEND = 'audience_spend';

    /** section key → the delivery dimensions it draws */
    public const DELIVERY = [
        'audience_regions' => ['region', 'country'],
        'audience_cities' => ['city'],
        'audience_gender' => ['gender'],
        'audience_age' => ['age'],
        'audience_devices' => ['device'],
        'audience_placements' => ['placement'],
        'audience_interests' => ['interests'],
        'audience_professional' => ['professional'],
    ];

    /** Every key that carries a payload. */
    public const KEYS = ['audience_targeting', 'audience_regions', 'audience_cities', 'audience_gender', 'audience_age', 'audience_devices', 'audience_placements', 'audience_interests', 'audience_professional', 'audience_site'];

    public function __construct(private readonly AudienceCenter $center) {}

    /**
     * @param  callable(string): bool  $allows  whether the operator left a section on
     * @param  list<string>|null  $providers  the link's platform ceiling, or null for all
     * @param  list<string>|null  $accountIds  the link's account ceiling (our ids), or null for all
     * @return array<string, array<string, mixed>|null>
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, bool $clientFacing, callable $allows, ?array $providers = null, bool $namesCampaigns = false, ?array $accountIds = null, bool $hideSpend = false): array
    {
        $out = array_fill_keys(self::KEYS, null);
        $wanted = array_values(array_filter(self::KEYS, $allows));
        if ($wanted === [] || $namesCampaigns) {
            return $out;
        }

        $noMoney = $clientFacing || $hideSpend || ! $allows(self::SPEND);
        $reading = $this->center->build($tenantId, $projectId, $from, $to, $clientFacing, $noMoney, $providers, $accountIds);
        $period = $reading['period'];

        if (in_array('audience_targeting', $wanted, true) && ($reading['targeting']['state'] ?? null) === 'ready') {
            $out['audience_targeting'] = ['kind' => 'targeting', 'period' => $period, 'items' => $reading['targeting']['items']];
        }

        foreach (self::DELIVERY as $key => $dimensions) {
            if (! in_array($key, $wanted, true)) {
                continue;
            }
            $groups = array_values(array_filter($reading['paid_delivery']['groups'], static fn (array $g): bool => in_array($g['dimension'], $dimensions, true)));
            $missing = array_values(array_filter($reading['paid_delivery']['unavailable'], static fn (array $m): bool => in_array($m['dimension'], $dimensions, true)));
            if ($groups !== []) {
                $out[$key] = ['kind' => 'paid_delivery', 'period' => $period, 'summed_across_platforms' => false, 'groups' => $groups, 'unavailable' => $missing];
            }
        }

        if (in_array('audience_site', $wanted, true) && ($reading['site_events']['state'] ?? null) === 'ready') {
            $out['audience_site'] = ['kind' => 'site_events', 'period' => $period, 'groups' => $reading['site_events']['groups']];
        }

        return $out;
    }

    /**
     * Every money figure out of every audience part — the KPI list that leaves spend out, applied to a stored snapshot too.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withoutMoney(array $payload): array
    {
        foreach (array_keys(self::DELIVERY) as $key) {
            if (! is_array($payload[$key]['groups'] ?? null)) {
                continue;
            }
            foreach ($payload[$key]['groups'] as $g => $group) {
                foreach ((array) ($group['rows'] ?? []) as $r => $row) {
                    unset($payload[$key]['groups'][$g]['rows'][$r]['spend']);
                }
            }
        }

        return $payload;
    }
}
