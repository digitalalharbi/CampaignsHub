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
            /*
             * The roster goes; how MANY stays.
             *
             * A count is not an identity — it names nothing and reveals no arrangement beyond «this
             * path's budget is split three ways», which the digest email has always told a client
             * and which is a fact about scale rather than about our filing. Keeping it is also what
             * stops two client surfaces stating different things about one period: the email says
             * «3 حملة» on the awareness path, and a report that silently dropped the line would
             * disagree with it in front of the same reader.
             */
            $objective['paths'][$i]['campaigns_count'] = count($path['campaigns'] ?? []);
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
     * Ad rows with our own primary keys taken out — CLIENT-REPORT-ENTITY-BOUNDARY-001.
     *
     * Found on the owner's live client link on 2026-09-07: `ads[]` and `ads_groups[].ads[]` carried
     * 22 internal UUIDs between them, as `id` and `campaign_id`. The campaign NAME is correctly
     * withheld and has a test of its own; a stable primary key for the very entity being withheld
     * went out beside it, in a payload anyone holding the link can read.
     *
     * The snapshot path has removed `campaign_id` since it was written — «internal id — never expose
     * to a client», in {@see ClientReportView} — and the live path never learned the same rule. That
     * is this class's own stated reason for existing, met again: a boundary implemented in one of
     * two paths is a boundary that holds until somebody opens the other. So the rule moves here and
     * both paths call it.
     *
     * `id` goes too. Its only use on a client surface is a React key, and `ReportAdsSection` already
     * falls back to the family and index when it is absent — so nothing on the page depends on it,
     * and an identifier that survives every window and filter is exactly what a boundary is for.
     *
     * @param  list<array<string,mixed>>  $ads
     * @return list<array<string,mixed>>
     */
    public static function ads(array $ads): array
    {
        return array_map(static function (array $ad): array {
            unset($ad['id'], $ad['campaign_id'], $ad['external_account_id'], $ad['client_display_name']);

            /* A group carries its own ads, and they are the same rows with the same keys. */
            if (isset($ad['ads']) && is_array($ad['ads'])) {
                $ad['ads'] = self::ads($ad['ads']);
            }

            return $ad;
        }, $ads);
    }

    /**
     * Roster rows with everything internal taken out — REPORT-CREATIVE-TRUTH-001 §C.
     *
     * ## Why `ads()` above is the wrong function for these
     *
     * The roster is the PRESENTED creative row, not the ranker's flattened one, and they are not the
     * same shape. A presented row carries `campaign_name`, `ad_set_id` and — this is the trap — its
     * own `ads` key holding every ExternalAd on the creative, each with `external_id`,
     * `external_campaign_id` and `external_ad_set_id`.
     *
     * `ads()` has an `isset($ad['ads'])` branch, written for an objective GROUP whose `ads` are more
     * ad rows. Handed a roster row it would take that branch, walk into the platform ads, strip the
     * two keys it knows and hand the other three straight to the link. Nothing would have failed:
     * one key name meaning two things, and the boundary quietly passing through the door it was
     * built to close.
     *
     * ## And the campaign name
     *
     * «Never restore campaign names into client reports where they were removed.» `ads()` never had
     * to strip it because the ranked rows never carried it; the presented row does, on every entry
     * of the longest list in the document.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    public static function roster(array $rows): array
    {
        return array_map(static function (array $row): array {
            unset(
                $row['id'],
                $row['campaign_id'],
                $row['campaign_name'],
                $row['ad_set_id'],
                $row['external_account_id'],
                $row['client_display_name'],
                /* The platform's own ad objects — internal ids end to end, and nothing here shows them. */
                $row['ads'],
            );

            return $row;
        }, $rows);
    }

    /**
     * A coverage block with the operator's EVIDENCE taken out and its verdict left behind.
     *
     * `AggregateCoverage::reasons` is documented as «contributor → human-readable evidence for its
     * state», and that evidence is written for whoever can act on it. On the owner's live client
     * link it read, in English, on an Arabic report:
     *
     *     "The last sync failed: No connector is registered for provider 'sandbox'."
     *
     * Three things a client must not be handed at once — an internal exception message, the name of
     * an internal artefact, and the implication that their figures depend on our plumbing. The
     * client's question is «are these numbers whole», and `state` with the contributor lists answers
     * it completely. WHY a sync failed is ours.
     *
     * The lists themselves stay. «Snapchat is in these figures and Meta is not» is a fact about the
     * client's own money that the report already states in a sentence, and removing it would leave a
     * `partial` verdict with nothing to explain it — which is the failure this boundary's sibling
     * method exists to prevent, arrived at from the other side.
     *
     * Applied to every coverage block on the payload, at any depth: `totals` carries `coverage`,
     * `spend_coverage` and `revenue_coverage` today, and a fourth added later must not quietly ship
     * an exception message because this method named only three.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function coverage(array $data): array
    {
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            /*
             * A coverage block is recognised by its shape, not its key. Keying on the name would
             * miss `spend_coverage` the day somebody adds `lead_coverage`, and a boundary that has
             * to be told about each new field is one that holds until the next feature.
             */
            if (array_key_exists('state', $value) && array_key_exists('reasons', $value)) {
                $value['reasons'] = [];
            }

            $data[$key] = self::coverage($value);
        }

        return $data;
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
