<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;

/**
 * What a report of this kind is allowed to say — §14.6.
 *
 * ## The rule, stated once
 *
 * A report leads with the metrics its money was spent to move, and it does not print a figure whose
 * question was never asked of that money. An awareness report has no cost per order because it bought
 * no orders; a traffic report has no ROAS. The arithmetic for both exists and would produce a number,
 * which is the danger: nothing on the page tells a reader that the figure is meaningless, and the one
 * they act on is next month's budget.
 *
 * `MarketingPath::headlineMetrics()` says this for the analytics engine. This says it for the report,
 * in the report's own vocabulary, and it is what the narrative and the leader board both read.
 *
 * ## Why the objective is inferred from DECLARATIONS, not from outcomes
 *
 * The old rule was «revenue present → sales, else conversions → leads, else traffic». That reads the
 * result and calls it the intent, so a brand campaign that happened to catch a few sales became a
 * sales report, and — worse — a pure awareness month with no clicks and no orders was filed as
 * TRAFFIC and given a layout built around CPC.
 *
 * The campaigns now carry what each platform said they were for (REPORT-OBJECTIVE-002). Only a
 * DECLARED objective counts: the column defaults to `other`, so reading the value alone would treat
 * every unclassified project as brand spend. When nothing is declared the old heuristic still runs,
 * so a project that predates objective derivation reports exactly as it did before.
 */
final class ReportObjectiveLens
{
    /** The report's own objective vocabulary — the keys `ReportTemplateEngine::METRIC_SETS` is cut to. */
    private const SALES = 'sales';

    private const AWARENESS = 'awareness';

    private const TRAFFIC = 'traffic';

    private const LEADS = 'leads';

    private const APP_INSTALLS = 'app_installs';

    private const VIDEO = 'video';

    /** Several objectives with no single honest headline. Operational figures only. */
    private const MIXED = 'custom';

    public function __construct(private readonly string $objective) {}

    public function value(): string
    {
        return $this->objective;
    }

    /**
     * The report objective for a scope, from the campaigns' own declarations.
     *
     * @param  list<array<string,mixed>>  $campaigns  rows from `MetricsAggregator::byCampaign()`
     */
    public static function infer(array $campaigns): self
    {
        $declared = [];
        foreach ($campaigns as $c) {
            // A campaign that spent nothing this period does not get a vote on what the period was
            // about; it would otherwise let one dormant brand campaign relabel a sales month.
            if ((float) ($c['spend'] ?? 0) <= 0) {
                continue;
            }
            if (($c['objective_source'] ?? 'unset') === 'unset') {
                continue;
            }
            $objective = CampaignObjective::tryFrom((string) ($c['objective'] ?? ''));
            if ($objective !== null) {
                $declared[] = $objective;
            }
        }

        if ($declared === []) {
            return new self(self::fromOutcomes($campaigns));
        }

        $reports = array_values(array_unique(array_map(self::reportObjectiveOf(...), $declared)));
        if (count($reports) === 1) {
            return new self($reports[0]);
        }

        // No single objective — but they may still share one PATH, which does have an honest
        // headline. Compared by VALUE: `array_unique` stringifies, and an enum instance cannot be.
        $paths = array_values(array_unique(array_map(fn (CampaignObjective $o) => $o->path()->value, $declared)));
        if (count($paths) === 1) {
            return new self(match (MarketingPath::from($paths[0])) {
                MarketingPath::Awareness => self::AWARENESS,
                MarketingPath::Traffic => self::TRAFFIC,
                // Leads and sales both live here. `leads` leads with results and cost per result,
                // which is true of either; `sales` would put a ROAS on lead spend.
                MarketingPath::Conversion => array_reduce(
                    $declared,
                    fn (bool $carry, CampaignObjective $o) => $carry && $o->isSales(),
                    true,
                ) ? self::SALES : self::LEADS,
            });
        }

        return new self(self::MIXED);
    }

    /** The pre-derivation heuristic, kept for scopes where nothing has been classified. */
    private static function fromOutcomes(array $campaigns): string
    {
        $revenue = array_sum(array_map(fn ($c) => (float) ($c['revenue'] ?? 0), $campaigns));
        $conversions = array_sum(array_map(fn ($c) => (float) ($c['conversions'] ?? 0), $campaigns));

        return $revenue > 0 ? self::SALES : ($conversions > 0 ? self::LEADS : self::TRAFFIC);
    }

    private static function reportObjectiveOf(CampaignObjective $objective): string
    {
        return match ($objective) {
            CampaignObjective::VideoViews => self::VIDEO,
            CampaignObjective::AppInstalls => self::APP_INSTALLS,
            CampaignObjective::Leads => self::LEADS,
            CampaignObjective::Traffic, CampaignObjective::LandingPageViews, CampaignObjective::StoreVisits => self::TRAFFIC,
            CampaignObjective::Sales, CampaignObjective::Purchases,
            CampaignObjective::Conversions, CampaignObjective::AddToCart => self::SALES,
            default => self::AWARENESS,
        };
    }

    /**
     * Whether revenue-based figures — ROAS, revenue growth, a cost per ORDER — belong in this report.
     *
     * False does not mean the figures are hidden. `objective_performance` still states Direct and
     * Blended side by side on every report, including this one, because «this money did not buy
     * sales» is itself the answer a reader deserves. What false forbids is LEADING with them, or
     * naming a platform «best» on a return it was never buying.
     */
    public function judgesOnRevenue(): bool
    {
        return $this->objective === self::SALES;
    }

    /** Whether a cost per RESULT is a fair way to rank this report's platforms. */
    public function judgesOnCostPerResult(): bool
    {
        return in_array($this->objective, [self::SALES, self::LEADS, self::APP_INSTALLS], true);
    }

    /** Several objectives in one scope: operational figures only, never a blended cost or return. */
    public function isMixed(): bool
    {
        return $this->objective === self::MIXED;
    }

    /**
     * The metric a platform is ranked on, and the direction that is good.
     *
     * @return array{key:string, label_ar:string, lower_is_better:bool}
     */
    public function rankingMetric(): array
    {
        return match ($this->objective) {
            // METRIC-NAMES-001 — the words the person paying for the campaign already uses. «ROAS»
            // is jargon to everyone outside media buying, and it is the figure a client is most
            // likely to be quoted back.
            self::SALES => ['key' => 'roas', 'label_ar' => 'العائد على الإنفاق', 'lower_is_better' => false],
            self::LEADS => ['key' => 'cpa', 'label_ar' => 'تكلفة العميل المحتمل', 'lower_is_better' => true],
            self::APP_INSTALLS => ['key' => 'cpa', 'label_ar' => 'تكلفة التحميل', 'lower_is_better' => true],
            self::TRAFFIC => ['key' => 'cpc', 'label_ar' => 'تكلفة النقرة', 'lower_is_better' => true],
            self::AWARENESS, self::VIDEO => ['key' => 'cpm', 'label_ar' => 'تكلفة الألف ظهور', 'lower_is_better' => true],
            // Mixed: the one figure that means the same thing whatever each campaign was for.
            default => ['key' => 'ctr', 'label_ar' => 'نسبة النقر', 'lower_is_better' => false],
        };
    }

    /** How a value of the ranking metric is written into a sentence. */
    public function formatRanking(float $value, string $currency): string
    {
        return match ($this->rankingMetric()['key']) {
            'roas' => number_format($value, 2).'×',
            'ctr' => number_format($value * 100, 2).'%',
            default => number_format($value, 2).' '.$currency,
        };
    }
}
