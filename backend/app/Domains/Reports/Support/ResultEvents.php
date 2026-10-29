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
    /** `native_form` is accepted as another name for `lead_form` and stored as `lead_form`, so existing configurations keep their key. */
    private const ALIASES = ['native_form' => 'lead_form'];

    public const METRICS = ['leads', 'form_leads', 'qualified_leads', 'conversions', 'registrations', 'purchases', 'add_to_cart', 'checkout', 'installs', 'landing_page_views'];

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
                $path = self::ALIASES[$path] ?? $path;
                if (in_array($path, self::PATHS, true) && is_string($metric) && in_array($metric, self::METRICS, true)) {
                    $map[$key][(string) $path] = $metric;
                }
            }
        }

        return new self($map);
    }

    /**
     * What is wrong with a serialized map, in words; empty when every platform, path and metric is one this product knows.
     *
     * `fromArray` silently drops what it does not know — right for reading stored settings, wrong for a request: an operator who chose
     * «native_form → form_leads» for a platform we do not support must hear so, not find the choice quietly missing on the client's page.
     *
     * @return list<string>
     */
    public static function problems(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw)) {
            return ['must be a map of platform → path → metric'];
        }
        $out = [];
        foreach ($raw as $platform => $paths) {
            if (! in_array(AdPlatforms::canonical((string) $platform), AdPlatforms::ORDER, true)) {
                $out[] = "unknown platform «{$platform}»";

                continue;
            }
            if (! is_array($paths)) {
                $out[] = "«{$platform}» must map paths to metrics";

                continue;
            }
            foreach ($paths as $path => $metric) {
                if (! in_array(self::ALIASES[$path] ?? $path, self::PATHS, true)) {
                    $out[] = "unknown path «{$path}» for «{$platform}»";
                }
                if (! is_string($metric) || ! in_array($metric, self::METRICS, true)) {
                    $out[] = 'unknown metric «'.(is_scalar($metric) ? (string) $metric : gettype($metric))."» for «{$platform}»";
                }
            }
        }

        return $out;
    }

    /** Only the platforms named — a link's events never configure a platform the link does not cover. @param list<string> $platforms */
    public function onlyFor(array $platforms): self
    {
        $keep = array_map(static fn (string $p): string => AdPlatforms::canonical($p), $platforms);

        return new self(array_intersect_key($this->map, array_flip($keep)), $this->patterns);
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
            $path = is_string($path) ? (self::ALIASES[$path] ?? $path) : $path;
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
     * @return array{configured: bool, total: float|null, landing: float|null, native: float|null, native_by_platform: array<string, float>, landing_by_platform: array<string, float>, landing_funnel: array<string, mixed>, cost: float|null, by_platform: array<string, array{results: float, metric: string, spend: float, cost: float|null}>, by_path: array<string, float>, events: array<string, array<string, string>>, basis: string}
     */
    public function leadBreakdown(array $rows, array $destinations): array
    {
        $o = $this->over($rows, $destinations);

        return [
            'configured' => $o['configured'],
            'total' => $o['total'],
            'landing' => $o['by_path']['landing_page'] ?? null,
            'native' => $o['by_path']['lead_form'] ?? null,
            // NASEL 2026-10-29 — the same figures per platform and path, so a platform card can show native leads alone and add up to `native`.
            'native_by_platform' => array_map(static fn (array $paths): float => $paths['lead_form'], array_filter($o['by_platform_path'], static fn (array $paths): bool => isset($paths['lead_form']))),
            'landing_by_platform' => array_map(static fn (array $paths): float => $paths['landing_page'], array_filter($o['by_platform_path'], static fn (array $paths): bool => isset($paths['landing_page']))),
            'landing_funnel' => $this->landingFunnel($rows, $destinations),
            'cost' => $o['cost'],
            'by_platform' => $o['by_platform'],
            'by_path' => $o['by_path'],
            'events' => $o['events'],
            'basis' => 'platform_attributed_not_unique_people',
        ];
    }

    /**
     * The Owner's landing-page events per platform (NASEL 2026-10-29): which platforms report a form START as InitiateCheckout. Snapchat and
     * X are not in it — their start event was never established — so their landing campaigns contribute visits and completions only.
     */
    private const FORM_START_PLATFORMS = ['meta', 'tiktok'];

    /**
     * NASEL 2026-10-29 — the landing-page funnel, over ONLY the campaigns this resolver puts on the `landing_page` path: visits, form starts
     * (InitiateCheckout where it means one), the configured completion event, the completion rate and the cost per completed form. A figure no
     * landing campaign reported is null («not available»), never zero. Contact clicks (WhatsApp / call / email) are not ingested from any ad
     * platform and GA4 is a separate source, so they are null here rather than guessed; nothing is deduplicated because no event_id evidence links
     * platform events to submissions. The completions are platform-attributed, not unique confirmed submissions.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, LeadDestination>  $destinations
     * @return array{campaigns: int, page_views: float|null, form_starts: float|null, contacts: null, completions: float|null, completion_rate: float|null, spend: float|null, cost_per_completion: float|null, basis: string}
     */
    public function landingFunnel(array $rows, array $destinations): array
    {
        $sum = ['page_views' => null, 'form_starts' => null, 'completions' => null];
        $spend = 0.0;
        $withheld = false;
        $campaigns = 0;
        foreach ($rows as $row) {
            $provider = AdPlatforms::canonical((string) ($row['provider'] ?? ''));
            $path = self::pathOf($destinations[(string) ($row['campaign_id'] ?? '')] ?? null) ?? $this->pathOfName((string) ($row['campaign_name'] ?? ''));
            if ($path !== 'landing_page') {
                continue;
            }
            $campaigns++;
            $spend += (float) ($row['spend'] ?? 0);
            $withheld = $withheld || (int) ($row['spend_withheld_rows'] ?? 0) > 0;
            $reported = (array) ($row['reported'] ?? []);
            $read = static fn (string $key): ?float => ($reported[$key] ?? false) === true ? (float) ($row[$key] ?? 0) : null;
            $parts = [
                'page_views' => $read('landing_page_views'),
                'form_starts' => in_array($provider, self::FORM_START_PLATFORMS, true) ? $read('checkout') : null,
                'completions' => ($metric = $this->metricFor($provider, 'landing_page')) === null ? null : $read($metric),
            ];
            foreach ($parts as $key => $value) {
                if ($value !== null) {
                    $sum[$key] = ($sum[$key] ?? 0.0) + $value;
                }
            }
        }

        $completions = $sum['completions'];

        return [
            'campaigns' => $campaigns,
            'page_views' => $sum['page_views'],
            'form_starts' => $sum['form_starts'],
            'contacts' => null,
            'completions' => $completions,
            'completion_rate' => $completions !== null && $sum['form_starts'] !== null && $sum['form_starts'] > 0 ? round($completions / $sum['form_starts'], 4) : null,
            'spend' => $campaigns > 0 && ! $withheld ? round($spend, 2) : null,
            'cost_per_completion' => $completions !== null && $completions > 0 && ! $withheld ? round($spend / $completions, 4) : null,
            'basis' => 'platform_attributed_not_unique_submissions',
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
     * @return array{configured: bool, total: float|null, cost: float|null, by_platform: array<string, array{results: float, metric: string, spend: float, cost: float|null}>, by_path: array<string, float>, by_platform_path: array<string, array<string, float>>, events: array<string, array<string, string>>}
     */
    public function over(array $rows, array $destinations): array
    {
        $total = 0.0;
        $spend = 0.0;
        $any = false;
        $withheld = false;
        $byPlatform = [];
        $byPath = [];
        $byPlatformPath = [];

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
            // The platform's ELIGIBLE spend: only rows whose configured event was reported, so its cost is the same event over the same money.
            $byPlatform[$provider]['spend'] = ($byPlatform[$provider]['spend'] ?? 0.0) + (float) ($row['spend'] ?? 0);
            $byPlatform[$provider]['withheld'] = ($byPlatform[$provider]['withheld'] ?? false) || (int) ($row['spend_withheld_rows'] ?? 0) > 0;
            $byPath[$path ?? 'all'] = ($byPath[$path ?? 'all'] ?? 0.0) + $value;
            $byPlatformPath[$provider][$path ?? 'all'] = ($byPlatformPath[$provider][$path ?? 'all'] ?? 0.0) + $value;
        }

        foreach ($byPlatform as $provider => $p) {
            // Zero results never produce a cost, and a cost over withheld money is absent rather than smaller.
            $byPlatform[$provider]['cost'] = $p['results'] > 0 && ! $p['withheld'] ? round($p['spend'] / $p['results'], 4) : null;
            $byPlatform[$provider]['spend'] = round($p['spend'], 2);
            unset($byPlatform[$provider]['withheld']);
        }

        return [
            'configured' => $this->configured(),
            // Configured but nothing reported yet is NULL («not available»), never a measured zero.
            'total' => $any ? round($total, 2) : null,
            // A cost per result over withheld money, or over no results, is absent rather than smaller or infinite.
            'cost' => $any && $total > 0 && ! $withheld ? round($spend / $total, 4) : null,
            'by_platform' => $byPlatform,
            'by_path' => $byPath,
            'by_platform_path' => $byPlatformPath,
            'events' => $this->map,
        ];
    }
}
