<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Campaigns\Enums\LeadDestination;
use App\Domains\Projects\Models\Project;
use App\Support\AdPlatforms;

/**
 * RESULT-EVENTS-001 — what «Results» MEANS, as an explicit choice and never an inference.
 *
 * A platform's `conversion` is whatever event the campaign was optimised for: a landing-page load, a view of content, a form open, a
 * purchase. Printing it as «Results» (or as «Leads») told a client that 700 people did something nobody had established they did. A
 * report therefore has Results only where somebody CONFIGURED which reported metric is the result, for which platform and which
 * acquisition path; where nothing is configured Results and Cost per Result do not exist on a client report at all.
 *
 * ```
 * { "tiktok": { "lead_form": "leads", "landing_page": "registrations" }, "snapchat": { "all": "leads" } }
 * ```
 *
 * Paths: `lead_form` (the platform's native form) and `landing_page` (the advertiser's own page) come from a campaign's stated
 * destination; a campaign whose destination is not stated matches only an `all` entry. The mapped value is a metric key the platforms
 * actually report, so a mapping cannot name an event we do not have. A per-report override replaces the project's mapping for the
 * platforms it names.
 */
final class ResultEvents
{
    public const PATHS = ['lead_form', 'landing_page', 'all'];

    /** Reported base metrics a result may be defined as — never a ratio, never the generic `conversions` unless chosen on purpose. */
    public const METRICS = ['leads', 'qualified_leads', 'conversions', 'registrations', 'purchases', 'add_to_cart', 'checkout', 'installs', 'landing_page_views'];

    /**
     * @param  array<string, array<string, string>>  $map  platform → path → metric key
     * @param  array<string, string>  $patterns  a word in a campaign's name → the acquisition path it states (the project's own naming convention)
     */
    private function __construct(private readonly array $map, private readonly array $patterns = []) {}

    /** @param array<string, mixed>|null $raw */
    public static function fromArray(?array $raw): self
    {
        $map = [];
        foreach ((array) $raw as $platform => $paths) {
            $key = AdPlatforms::canonical((string) $platform);
            if (! in_array($key, AdPlatforms::ORDER, true) || ! is_array($paths)) {
                continue;
            }
            foreach ($paths as $path => $metric) {
                if (in_array($path, self::PATHS, true) && is_string($metric) && in_array($metric, self::METRICS, true)) {
                    $map[$key][(string) $path] = $metric;
                }
            }
        }

        return new self($map);
    }

    /**
     * The project's mapping with a report's override laid over it, platform by platform.
     *
     * @param  array<string, mixed>|null  $campaignPaths  the project's explicit «word in the name → path» convention, e.g. {"LeadForm": "lead_form"}
     */
    public static function merged(?array $project, ?array $override, ?array $campaignPaths = null): self
    {
        $base = self::fromArray($project)->map;
        foreach (self::fromArray($override)->map as $platform => $paths) {
            $base[$platform] = $paths;
        }

        $patterns = [];
        foreach ((array) $campaignPaths as $word => $path) {
            if (is_string($word) && trim($word) !== '' && in_array($path, ['lead_form', 'landing_page'], true)) {
                $patterns[trim($word)] = (string) $path;
            }
        }

        return new self($base, $patterns);
    }

    /**
     * A project's mapping under `$key` (`result_events` or `lead_events`), a link's override over it, and the project's naming convention.
     *
     * @param  array<string, mixed>|null  $override
     */
    public static function forProject(?Project $project, string $key, ?array $override): self
    {
        $meta = $project === null ? [] : (array) $project->meta;

        return self::merged((array) ($meta[$key] ?? []) ?: null, $override ?: null, (array) ($meta['campaign_paths'] ?? []) ?: null);
    }

    /** The path a campaign's NAME states under the project's own convention; null where it states none. */
    public function pathOfName(string $name): ?string
    {
        foreach ($this->patterns as $word => $path) {
            if (mb_stripos($name, $word) !== false) {
                return $path;
            }
        }

        return null;
    }

    public function configured(): bool
    {
        return $this->map !== [];
    }

    /** The metric that is THE result for a platform and acquisition path, or null when none is configured. */
    public function metricFor(string $provider, ?string $path): ?string
    {
        $paths = $this->map[AdPlatforms::canonical($provider)] ?? [];

        return ($path !== null ? ($paths[$path] ?? null) : null) ?? ($paths['all'] ?? null);
    }

    /** The acquisition path a campaign's stated destination puts it on; null where none is stated. */
    public static function pathOf(?LeadDestination $destination): ?string
    {
        return match ($destination) {
            LeadDestination::LeadForm => 'lead_form',
            LeadDestination::Website => 'landing_page',
            default => null,
        };
    }

    /**
     * LEAD-EVENTS-001 — verified Leads, by source: completed landing-page forms and completed native platform forms, kept apart.
     *
     * Each platform contributes ONE mapped metric per path, so an event that duplicates another (Meta's pixel `Lead` beside its `Purchase`) is
     * never added twice. The total is the platforms' own attributions summed — it is NOT a count of unique people, because two platforms can
     * claim the same person and nothing here proves otherwise.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, LeadDestination>  $destinations
     * @return array{configured: bool, total: float|null, landing: float|null, native: float|null, cost: float|null, by_platform: array<string, array{results: float, metric: string}>, by_path: array<string, float>, events: array<string, array<string, string>>, basis: string}
     */
    public function leadBreakdown(array $rows, array $destinations): array
    {
        $o = $this->over($rows, $destinations);

        return [
            'configured' => $o['configured'],
            'total' => $o['total'],
            'landing' => $o['by_path']['landing_page'] ?? null,
            'native' => $o['by_path']['lead_form'] ?? null,
            'cost' => $o['cost'],
            'by_platform' => $o['by_platform'],
            'by_path' => $o['by_path'],
            'events' => $o['events'],
            'basis' => 'platform_attributed_not_unique_people',
        ];
    }

    /** @return array<string, array<string, string>> */
    public function toArray(): array
    {
        return $this->map;
    }

    /**
     * Results over campaign rows (`MetricsAggregator::byCampaign`): only configured combinations contribute, and only where the
     * platform actually REPORTED the mapped metric — an unreported metric is absence, not a zero.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, LeadDestination>  $destinations  campaign id → destination
     * @return array{configured: bool, total: float|null, cost: float|null, by_platform: array<string, array{results: float, metric: string}>, by_path: array<string, float>, events: array<string, array<string, string>>}
     */
    public function over(array $rows, array $destinations): array
    {
        $total = 0.0;
        $spend = 0.0;
        $any = false;
        $withheld = false;
        $byPlatform = [];
        $byPath = [];

        foreach ($rows as $row) {
            $provider = AdPlatforms::canonical((string) ($row['provider'] ?? ''));
            // A stated destination outranks the name; a name states a path only under the project's own, explicit convention.
            $path = self::pathOf($destinations[(string) ($row['campaign_id'] ?? '')] ?? null) ?? $this->pathOfName((string) ($row['campaign_name'] ?? ''));
            $metric = $this->metricFor($provider, $path);
            if ($metric === null || ($row['reported'][$metric] ?? false) !== true) {
                continue;
            }
            $value = (float) ($row[$metric] ?? 0);
            $any = true;
            $total += $value;
            $spend += (float) ($row['spend'] ?? 0);
            $withheld = $withheld || (int) ($row['spend_withheld_rows'] ?? 0) > 0;
            $byPlatform[$provider]['metric'] = $metric;
            $byPlatform[$provider]['results'] = ($byPlatform[$provider]['results'] ?? 0.0) + $value;
            $byPath[$path ?? 'all'] = ($byPath[$path ?? 'all'] ?? 0.0) + $value;
        }

        return [
            'configured' => $this->configured(),
            // Configured but nothing reported yet is NULL («not available»), never a measured zero.
            'total' => $any ? round($total, 2) : null,
            // A cost per result over withheld money, or over no results, is absent rather than smaller or infinite.
            'cost' => $any && $total > 0 && ! $withheld ? round($spend / $total, 4) : null,
            'by_platform' => $byPlatform,
            'by_path' => $byPath,
            'events' => $this->map,
        ];
    }
}
