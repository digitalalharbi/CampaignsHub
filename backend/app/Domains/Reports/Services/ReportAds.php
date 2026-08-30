<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
use Illuminate\Support\Carbon;

/**
 * REPORT-AD-PREVIEW-001 — the ads a report shows, built ONCE for every surface that shows them.
 *
 * ## Why this is a service and not a private method
 *
 * It began as one, on `ReportGenerator`, and it produced the section for the generated snapshot. The
 * live client link computes its own figures from the same engine — deliberately, so an operator's
 * dashboard and a client's link cannot disagree about one number — and it had no ads at all. The
 * choice was to give the live path its own ad query, or to move this one where both can call it.
 *
 * A second implementation is how «best ad» comes to mean two different things in two documents about
 * one campaign, and the client is the person who owns both. So it moved.
 *
 * ## What it guarantees
 *
 * The same ranker (`CreativeRankingService`) on the same objective, the same 60-row bound before
 * ranking, and the same canonical preview from `CreativePresenter` — so an ad whose media was
 * withheld, expired or never sent says the same sentence in the deck, in the link and in the PDF.
 *
 * ## An absent section, not an empty one
 *
 * The reason travels with the result. «No ad-level rows in this window» and «the platforms reported
 * no metric this objective can be ranked on» are different facts, and a heading over an empty grid
 * is a claim about the client's advertising made by a gap in ours.
 */
final class ReportAds
{
    /** The most creatives presented before ranking — a scheduled report must not become 4,000 calls. */
    private const MAX_ROWS = 60;

    public function __construct(
        private readonly CreativeRows $creatives,
        private readonly CreativeRankingService $ranking,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  `project_ids`, `providers`, `campaign_ids` — the scope
     * @return array{ads: list<array<string,mixed>>, level: string, reason: string|null}
     */
    public function for(string $objective, Carbon $from, Carbon $to, array $filters = []): array
    {
        $query = ExternalCreative::query();
        $this->creatives->applyFilters($query, $filters + [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);

        $rows = $this->creatives->present(
            $this->creatives->applySort($query, 'spend', $from, $to)->limit(self::MAX_ROWS)->get(),
            $from,
            $to,
            withFatigue: false,
        );

        if ($rows === []) {
            return ['ads' => [], 'level' => 'campaign', 'reason' => 'no_creatives_in_window'];
        }

        // The same ranker the campaign leaders use, on the same objective — one definition of «best».
        $rankable = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'provider' => $row['provider'],
            'campaign_id' => $row['campaign_id'] ?? null,
            'campaign_name' => $row['campaign_name'] ?? null,
            'objective' => $row['objective'] ?? null,
            'preview' => $row['preview'],
            'format' => $row['format'] ?? null,
            'spend' => (float) ($row['metrics']['spend'] ?? 0),
            'impressions' => (float) ($row['metrics']['impressions'] ?? 0),
            'clicks' => (float) ($row['metrics']['clicks'] ?? 0),
            'conversions' => (float) ($row['metrics']['conversions'] ?? 0),
            'revenue' => (float) ($row['metrics']['revenue'] ?? 0),
            'ctr' => $row['metrics']['ctr'] ?? null,
            'cpa' => $row['metrics']['cpa'] ?? null,
            'roas' => $row['metrics']['roas'] ?? null,
        ], $rows);

        $ranked = $this->ranking->rank($objective, $rankable);

        return $ranked === []
            ? ['ads' => [], 'level' => 'ad', 'reason' => 'no_rankable_metric_for_this_objective']
            : ['ads' => $ranked, 'level' => 'ad', 'reason' => null];
    }
}
