<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * CLIENT-REPORT-ENTITY-BOUNDARY-001 — the line between what a client's money DID and how it was
 * arranged.
 *
 * The owner reported it from a report page: «اسم واختيار الحملة احذفه من التقارير لاحظت اختيار كل
 * الحملات! وظهور اسماءها وهذا غير مناسبة». A client report is about PERFORMANCE. A campaign name, an
 * ad-set name, an entity id, the targeting or bidding or placement strategy — those are the agency's
 * working notes, and a document written for the person paying is not where they belong.
 *
 * ## Why a class rather than a method on each service
 *
 * Two independent code paths produce client documents — {@see LiveReportService} recomputes one on
 * every open, {@see ReportGenerator} writes one down — and each has its own copy of the objective
 * split. A boundary implemented twice is a boundary that holds in one of them, which is how the
 * roster survived in the generated report after the live link stopped sending it.
 *
 * ## What this deliberately does NOT do
 *
 * It does not touch the operator's own surfaces. An operator reading «the sales figure excludes
 * 4,127 SAR» has to know which campaigns that was in order to act on it, and Campaign Management
 * keeps the whole hierarchy — Campaign → Ad Set → Ad → Content. The line is drawn at the document a
 * client receives, not inside the shared services that compute the figures.
 */
final class ClientEntityBoundary
{
    /**
     * The objective split, with the campaign roster taken out and its ARITHMETIC left behind.
     *
     * Emptying the lists alone would leave two numbers disagreeing on the page with no account of it:
     * the programme's total spend and the direct sales spend differ by exactly the campaigns that were
     * excluded, and the roster was the only thing that said so. `excluded_spend` and the reason it
     * carries — «not a sales objective» — answer that without naming one campaign.
     *
     * @param  array<string,mixed>  $objective
     * @return array<string,mixed>
     */
    public static function objectivePerformance(array $objective): array
    {
        foreach ($objective['paths'] ?? [] as $i => $path) {
            // The path's own totals already carry its spend and its results; the roster was detail.
            $objective['paths'][$i]['campaigns'] = [];
        }

        $excluded = $objective['direct']['excluded_campaigns'] ?? [];

        // `direct.spend` is already the included spend, so the included roster needs no replacement.
        $objective['direct']['included_campaigns'] = [];
        $objective['direct']['excluded_campaigns'] = [];
        $objective['direct']['excluded_spend'] = round(
            array_sum(array_map(fn ($c) => (float) ($c['spend'] ?? 0), $excluded)),
            2,
        );
        $objective['direct']['excluded_reasons'] = array_values(array_unique(array_map(
            fn ($c) => (string) ($c['reason'] ?? ''),
            $excluded,
        )));

        return $objective;
    }

    /**
     * The spend that produced nothing, and where it was — the finding the burner sentence carried.
     *
     * The observation it replaces read «حملة «X» تنفق دون تحويلات», which is the most actionable line
     * in the whole document and also a campaign name. Deleting the sentence with the name would have
     * been the easy way to satisfy the boundary and the wrong one: the money is still being spent.
     *
     * So it is stated as a sum and the platforms it sat on — both true, both a client's to act on,
     * and neither of them the campaign plan. A single campaign's waste is NOT attributed to its whole
     * platform: the sum is the waste itself, and the platform is only where to look for it.
     *
     * @param  list<array<string,mixed>>  $campaigns  rows from MetricsAggregator::byCampaign()
     * @return array{spend: float, providers: list<string>}|null null when nothing qualifies
     */
    public static function spendWithoutResults(array $campaigns, float $floor = 3000, int $under = 2): ?array
    {
        $burning = array_values(array_filter(
            $campaigns,
            fn ($c) => (float) ($c['spend'] ?? 0) > $floor && (float) ($c['conversions'] ?? 0) < $under,
        ));

        if ($burning === []) {
            return null;
        }

        return [
            'spend' => round(array_sum(array_map(fn ($c) => (float) ($c['spend'] ?? 0), $burning)), 2),
            'providers' => array_values(array_unique(array_filter(
                array_map(fn ($c) => (string) ($c['provider'] ?? ''), $burning),
            ))),
        ];
    }
}
