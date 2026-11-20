<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

/**
 * CLIENT-DASHBOARD-PARITY-001 — what a client may read of the agency's own metrics answers.
 *
 * The client dashboard reads the SAME endpoints the agency dashboard reads (one engine, one set of numbers). This is
 * the one place that decides what leaves the server: money keys are removed unless the agency switched `spend` /
 * `revenue` on for the space, and the platforms' technical identifiers are never sent. Removal is by key, at every
 * depth, so a figure nested in `current`, `previous`, `deltas` or a row cannot slip through under another parent.
 */
final class PortalMetricsFilter
{
    private const SPEND = ['spend', 'cpa', 'cpc', 'cpm', 'cpl', 'cpv', 'cost', 'budget'];

    private const REVENUE = ['revenue', 'roas', 'aov', 'purchase_value', 'conversion_value', 'conversions_value', 'profit', 'margin'];

    private const NEVER = ['external_id', 'external_account_id', 'account_external_id', 'provider_campaign_id', 'provider_account_id', 'ad_account_id', 'raw', 'payload'];

    /**
     * @param  array<string, bool>  $visibility
     */
    public static function apply(mixed $data, array $visibility): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::withheld($key, $visibility)) {
                continue;
            }
            $out[$key] = self::apply($value, $visibility);
        }

        return $out;
    }

    /** @param  array<string, bool>  $visibility */
    public static function withheld(string $key, array $visibility): bool
    {
        $k = strtolower($key);
        if (in_array($k, self::NEVER, true)) {
            return true;
        }
        if (! ($visibility['spend'] ?? false) && (self::matches($k, self::SPEND) || str_starts_with($k, 'cost_per'))) {
            return true;
        }
        // ROAS and cost-per figures need both sides: without spend there is no ratio to show either.
        if ((! ($visibility['revenue'] ?? false) || ! ($visibility['spend'] ?? false)) && self::matches($k, ['roas'])) {
            return true;
        }

        return ! ($visibility['revenue'] ?? false) && self::matches($k, self::REVENUE);
    }

    /** @param  list<string>  $stems */
    private static function matches(string $key, array $stems): bool
    {
        foreach ($stems as $stem) {
            if ($key === $stem || str_starts_with($key, $stem.'_') || str_ends_with($key, '_'.$stem)) {
                return true;
            }
        }

        return false;
    }
}
