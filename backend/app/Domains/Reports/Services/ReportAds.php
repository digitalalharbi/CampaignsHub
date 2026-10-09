<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
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
 * The same ranker (`CreativeRankingService`) on the same objective, the same bound before ranking, and the same canonical preview from `CreativePresenter` — so an ad whose media was
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
    /**
     * The most creatives a FIVE-PAGE SUMMARY presents — a deck is a curated document by definition.
     *
     * This was `MAX_ROWS`, applied to every report equally, and the count below is what makes it
     * honest: a summary that shows a handful now says how many it left out.
     */
    private const SUMMARY_ROWS = 60;

    /**
     * The most creatives PRESENTED as cards, for the ranked lists a report leads with.
     *
     * A presented row carries a preview envelope, the platform's ad objects and a headline layout,
     * and the ranked lists need every bit of it — «which of these worked» is answered with the
     * picture that ran. Five hundred candidates is far more than any ranking reads, and the cost is
     * per row rather than per query: `present()` is fully batched.
     */
    private const RANKING_CANDIDATES = 500;

    /**
     * And the roster is not bounded at all — REPORT-CREATIVE-TRUTH-001 §D.
     *
     * «A disclosed 65 ran / 60 listed cap alone is not completion.» §B made the bound honest and left
     * the five creatives past it unreachable, which is a disclosed gap and still a gap.
     *
     * What had been costing the bound was never the query. It was the presented CARD, stored in a
     * snapshot and parsed on every open. A roster is a table of names and figures and draws none of
     * it, so it is built lean — nine fields a row — and once a row is nine fields the bound that was
     * protecting the payload is protecting nothing. An account with four thousand creatives gets four
     * thousand rows, rendered a page at a time by the surface that reads them.
     */
    private const SUMMARY_ROSTER = self::SUMMARY_ROWS;

    /**
     * How many ads a single group publishes — the leaders, not the list.
     *
     * It was the literal `3` in two places, and a literal is how a bound becomes invisible: the
     * section said «الأعلى أداءً» over three cards with nothing saying three of how many. Named here,
     * and every group now publishes its `candidates` beside its `shown` so the page can say it.
     */
    private const GROUP_ADS = 3;

    public function __construct(
        private readonly CreativeRows $creatives,
        private readonly CreativeRankingService $ranking,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  `project_ids`, `providers`, `campaign_ids` — the scope
     * @param  string  $form  `executive_summary` curates; anything else is the full report
     * @return array{ads: list<array<string,mixed>>, worst: list<array<string,mixed>>, groups: list<array<string,mixed>>, platform_groups: list<array<string,mixed>>, roster: list<array<string,mixed>>, level: string, reason: string|null, creatives_in_scope: int, creatives_unmeasured: int, creatives_withheld: int}
     */
    public function for(string $objective, Carbon $from, Carbon $to, array $filters = [], string $form = 'detailed', bool $liveMedia = false): array
    {
        $query = ExternalCreative::query();
        $this->creatives->applyFilters($query, $filters + [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);

        /*
         * REPORT-CREATIVE-TRUTH-001 — how many creatives the scope holds, before the cap.
         *
         * «Full reports must be able to show all promoted creatives truthfully.» The row bound exists
         * for a real reason — a payload has to end somewhere — but it was SILENT, so a report showed a
         * curated handful and said nothing about the rest, which reads as «these are the ads that
         * ran».
         *
         * Counted on a clone, before the limit is applied: the builder is mutable and `->limit()`
         * below would otherwise cap the count too, which is the same mistake the content library's
         * totals made one unit ago.
         */
        $inScope = (clone $query)->count();

        /*
         * CONTENT-MEASURED-FIRST-001 §B — a report lists what the PERIOD measured.
         *
         * The scope was «every creative matching the filters», with no reference to the window at
         * all, so a creative that last ran a year before the period still arrived in the roster — at
         * the end, because the spend sort puts nulls last, but present, named, and on a page a client
         * reads. The owner found exactly that and said it makes the whole report look inaccurate.
         *
         * A report is a statement about a period, and a creative the period never measured has
         * nothing to state. Listing it with a dash down every column is worse than omitting it: it
         * reads as a creative that RAN and produced nothing, which is a claim the data does not make.
         *
         * A reported ZERO is measurement and stays — «the platform told us this spent nothing in this
         * period» is a fact about the period, and the client is entitled to it. The predicate is the
         * existence of a ROW, never the size of a figure, which is the same distinction the library's
         * ordering draws one rung up.
         *
         * Nothing is dropped silently. `creatives_in_scope` keeps meaning what it always meant, so
         * the section can still say «65 ran», and `creatives_unmeasured` says how many of those the
         * period holds no figure for. A report that quietly shrank its own estate would be this same
         * defect wearing the opposite sign.
         */
        $query->where(function ($outer) use ($from, $to): void {
            $outer
                ->whereExists(function ($sub) use ($from, $to): void {
                    $sub->selectRaw('1')
                        ->from('creative_daily_metrics')
                        ->whereColumn('creative_daily_metrics.creative_id', 'external_creatives.id')
                        ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);
                })
                /*
                 * …or measured one grain down, at the AD.
                 *
                 * Not every platform reports per creative. Where it reports per ad, the creative has
                 * no `creative_daily_metrics` row of its own and is still a creative that ran — the
                 * same path `lean()` already walks to decide whether an ad delivered. Reading only
                 * the creative grain dropped exactly those from the report, which is «unavailable
                 * treated as absent», the error this change exists to remove rather than relocate.
                 *
                 * Caught by `LiveReportContentAccountCeilingTest`, whose fixture records campaign and
                 * ad figures and no creative ones.
                 */
                ->orWhereExists(function ($sub) use ($from, $to): void {
                    $sub->selectRaw('1')
                        ->from('external_ads')
                        ->whereColumn('external_ads.creative_id', 'external_creatives.id')
                        ->whereExists(function ($inner) use ($from, $to): void {
                            $inner->selectRaw('1')
                                ->from('entity_daily_metrics')
                                ->where('entity_type', 'ad')
                                ->whereColumn('entity_daily_metrics.entity_id', 'external_ads.id')
                                ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);
                        });
                })
                /*
                 * …or the creative itself records that it was delivering inside the window.
                 *
                 * Some platforms report at the CAMPAIGN grain only: no creative row, no ad row, and a
                 * creative that genuinely ran. What the sync does record on it is `last_active_at`,
                 * which is the platform saying «this was delivering on this date». A date inside the
                 * period is the period measuring something about this creative, and that is the whole
                 * test being applied here.
                 *
                 * It is also what keeps this change narrow. What is excluded is content with NO
                 * evidence of activity in the period from ANY grain — the owner's «no spend and no
                 * indicators» — rather than everything a single table happens not to carry.
                 */
                ->orWhereBetween('external_creatives.last_active_at', [
                    $from->copy()->startOfDay(),
                    $to->copy()->endOfDay(),
                ]);
        });

        $measured = (clone $query)->count();
        $unmeasured = $inScope - $measured;

        /*
         * The roster FIRST, from its own lean read, because it is the one that must be complete.
         *
         * A five-page summary curates on purpose and keeps its bound; a full report takes the whole
         * estate. Ordered by spend descending, the same order the presented rows use, so the two
         * sections cannot disagree about which creative leads.
         */
        $rosterQuery = $this->creatives->applySort(clone $query, 'spend', $from, $to);

        if ($form === 'executive_summary') {
            $rosterQuery->limit(self::SUMMARY_ROSTER);
        }

        /*
         * `$liveMedia` — REPORT-CREATIVE-MEDIA-001. The LIVE link builds on every open and stores
         * nothing, so its roster can carry the resolved media; a generated snapshot must not, which
         * is what `lean()`'s own docblock is about. See `ReportCreativeMedia`, which gives a STORED
         * report its pictures at read time instead.
         */
        $roster = $this->creatives->lean($rosterQuery->get(), $from, $to, withPreview: $liveMedia);

        $rows = $this->creatives->present(
            $this->creatives->applySort($query, 'spend', $from, $to)->limit(self::RANKING_CANDIDATES)->get(),
            $from,
            $to,
            withFatigue: false,
        );

        if ($rows === []) {
            return [
                'ads' => [], 'worst' => [], 'groups' => [], 'platform_groups' => [], 'roster' => $roster, 'level' => 'campaign',
                'reason' => 'no_creatives_in_window',
                'creatives_in_scope' => $inScope,
                'creatives_unmeasured' => $unmeasured,
                'creatives_withheld' => $inScope,
            ];
        }

        // The same ranker the campaign leaders use, on the same objective — one definition of «best».
        $rankable = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'provider' => $row['provider'],
            'campaign_id' => $row['campaign_id'] ?? null,
            'objective' => $row['objective'] ?? null,
            'preview' => $row['preview'],
            'format' => $row['format'] ?? null,
            /*
             * REPORT-CONTENT-BROWSER-001 — the two facts «is this still running» is read from.
             *
             * The report's content section is ordered with running content first, and a client
             * looking at a table ordered by a fact it does not carry cannot account for the order.
             * They are FACTS about the ad, not figures: `status` is the platform's own word and
             * `last_active_at` is when it last delivered, and the reader (`relevanceOf`) is the one
             * the library and the campaigns workspace already use.
             *
             * Not a client-boundary concern: «this ad is running» is the client's own advertising,
             * unlike a campaign's name, configuration or internal state.
             */
            'status' => $row['status'] ?? null,
            'last_active_at' => $row['freshness']['last_active_at'] ?? null,
            /*
             * CLIENT-REPORT-MONEY-REDACTION-001 — the money truth, not `?? 0`.
             *
             * `?? 0` printed «0» as an ad's spend whenever FX-001 had withheld the conversion, which
             * is the one thing the owner named outright: never turn unavailable spend into zero. It
             * also made the ad disappear, because the ranker gates on «did it spend» — see
             * `CreativeRankingService::didSpend()`.
             *
             * `spend` stays null when the conversion is unavailable, and the original travels beside
             * it under the same key names the frontend money reader already uses, so the ad card
             * renders «412.50 USD» through the one contract rather than a second opinion.
             */
            /*
             * Owner defect 94h — every figure travels, then the money keys are restated deliberately.
             *
             * This projection named nine metrics by hand, so the report's RANKED creatives carried
             * `ctr`, `cpa` and `roas` and dropped `cpc`, `cpm`, `cpl`, `cpi`, `cpe`, `leads`,
             * `installs`, `sign_ups`, `app_opens`, `page_views`, `reach` and every video figure —
             * while the roster beside it and the content library carried them. The same creative
             * showed a cost per click on three surfaces and an empty cell on the fourth.
             *
             * Worse for a lead-generation report: the ranked ad had no CPL at all, which is the one
             * figure that section exists to justify its ranking with. The comment on the caller
             * already promised «the objective's own indicators beside the preview» — the projection
             * simply never kept it, which is a promise made where nothing computes it.
             *
             * Spreading first and restating after is deliberate: the money keys below carry
             * semantics a blanket copy would flatten (`spend` must stay NULL when the conversion is
             * unavailable, never `?? 0`), so they are written last and win.
             */
            // `?? []`, because a row without metrics reaches here: every read beside this one is
            // null-safe and a bare spread of a missing key is a fatal, which took the whole shared
            // client link to a 500 rather than to a report with fewer figures.
            ...($row['metrics'] ?? []),
            'spend' => $row['metrics']['spend'] ?? null,
            'spend_original' => $row['metrics']['spend_original'] ?? null,
            'spend_withheld_rows' => $row['metrics']['spend_withheld_rows'] ?? null,
            'money_original_currency' => $row['metrics']['money_original_currency'] ?? null,
            'money_original_currencies' => $row['metrics']['money_original_currencies'] ?? null,
            'impressions' => (float) ($row['metrics']['impressions'] ?? 0),
            'clicks' => (float) ($row['metrics']['clicks'] ?? 0),
            'conversions' => (float) ($row['metrics']['conversions'] ?? 0),
            'revenue' => (float) ($row['metrics']['revenue'] ?? 0),
            'ctr' => $row['metrics']['ctr'] ?? null,
            'cpa' => $row['metrics']['cpa'] ?? null,
            'roas' => $row['metrics']['roas'] ?? null,
        ], $rows);

        $ranked = $this->ranking->rank($objective, $rankable);

        /*
         * REPORT-WORST-CREATIVES-001 at ad level — what to stop, beside what to keep.
         *
         * Judged on the SAME metric as the leaders and excluding anything the platform did not
         * measure on it: «no return reported» is not «returned nothing», and a report a client keeps
         * is the last place to blur the two.
         */
        $weakest = $this->ranking->worst($objective, $rankable);

        /*
         * REPORT-AD-PREVIEW-001 §A — «top performing» is a claim, and it needs a metric that says so.
         *
         * A report whose campaigns span objectives resolves to the UNKNOWN family, whose fallback
         * order begins with SPEND — so production titled a section «الإعلانات التي عملت» and ordered
         * it «أعلى الإنفاق (578)». Spending most is not performing best; it is the one ordering that
         * can never be a judgement, and printed under a performance heading it tells a client their
         * biggest bill is their best ad.
         *
         * The ads are grouped by the objective each was bought for, ranked inside it on that
         * objective's own metric, and the metric travels with the group so the page can say what the
         * order means. A group whose objective reported none of its metrics carries a NULL metric:
         * the section then shows those ads without claiming an order over them.
         */
        $groups = $this->groupsByObjective($rankable);
        $platformGroups = $this->groupsByPlatform($rankable);

        /*
         * REPORT-CREATIVE-TRUTH-001 §B — what did we RUN, beside what worked.
         *
         * `ads`, `worst` and `groups` are three curated answers to «which of these performed». None of
         * them is an answer to «what did you run for me», and a client paying for forty creatives who
         * can see six asks the second question. The roster is every presented creative in spend order:
         * the same rows, the same presenter, the same figures — no second pipeline, and nothing here
         * is ranked, so it makes no claim the lists above have not already earned.
         */
        /*
         * What the ROSTER left out, which for a full report is now always nothing.
         *
         * Counted against the roster rather than the presented rows: the presented rows are ranking
         * candidates and were never the list a reader is owed. A summary's five hundredth creative is
         * still withheld, and says so.
         */
        $withheld = max(0, $inScope - count($roster));

        return $ranked === []
            ? ['ads' => [], 'worst' => [], 'groups' => $groups, 'platform_groups' => $platformGroups, 'roster' => $roster, 'level' => 'ad', 'reason' => 'no_rankable_metric_for_this_objective', 'creatives_in_scope' => $inScope,
                'creatives_unmeasured' => $unmeasured, 'creatives_withheld' => $withheld]
            : ['ads' => $ranked, 'worst' => $weakest, 'groups' => $groups, 'platform_groups' => $platformGroups, 'roster' => $roster, 'level' => 'ad', 'reason' => null, 'creatives_in_scope' => $inScope,
                'creatives_unmeasured' => $unmeasured, 'creatives_withheld' => $withheld];
    }

    /**
     * The ads, grouped by the objective they were bought for and ranked inside it.
     *
     * @param  list<array<string,mixed>>  $rankable
     * @return list<array<string,mixed>>
     */
    private function groupsByObjective(array $rankable): array
    {
        /** @var array<string, list<array<string,mixed>>> $byFamily */
        $byFamily = [];

        foreach ($rankable as $row) {
            $objective = (string) ($row['objective'] ?? '');
            $family = ObjectiveFamily::tryFrom($objective)
                ?? (CampaignObjective::tryFrom($objective)?->family() ?? ObjectiveFamily::Unknown);

            $byFamily[$family->value][] = $row;
        }

        $groups = [];

        foreach ($byFamily as $familyKey => $rows) {
            $groups[] = $this->familyGroup($familyKey, $rows);
        }

        return $groups;
    }

    /**
     * One objective family's group: the ranked ads, the metric the order rests on, and HOW MANY were
     * left out of it.
     *
     * Extracted so the per-platform section builds its groups with this rule rather than a second
     * copy of it. Two sections ranking «the best creatives» by two definitions of best is the defect
     * the objective grouping exists to remove, one axis over.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function familyGroup(string $familyKey, array $rows): array
    {
        $family = ObjectiveFamily::tryFrom($familyKey) ?? ObjectiveFamily::Unknown;
        $ranked = $this->ranking->rank($familyKey, $rows, self::GROUP_ADS);

        /*
         * The metric the ORDER rests on, read from the ranked rows rather than from the objective's
         * layout: the layout says what this family WOULD be judged on, and the rows say what anybody
         * actually reported. A group ranked on a metric no row carries is the defect this section
         * exists to remove.
         */
        $metric = $ranked === [] ? null : $this->ranking->metricFor($familyKey, $rows);
        $ads = $ranked === [] ? array_slice($rows, 0, self::GROUP_ADS) : $ranked;

        return [
            'family' => $family->value,
            'label_ar' => $family->label()['ar'] ?? $family->value,
            'label_en' => $family->label()['en'] ?? $family->value,
            'metric' => $metric,
            'metric_label_ar' => $metric === null ? null : RankingMetric::of((string) $metric)->labelAr,
            'metric_label_en' => $metric === null ? null : RankingMetric::of((string) $metric)->labelEn,
            // Ordered where a metric exists; otherwise the rows as they came, with no claim made.
            'ads' => $ads,
            'ranked' => $ranked !== [],
            /*
             * REPORT-CREATIVE-TRUTH-001 §B — «the best three» has to say «of how many».
             *
             * The group published three ads and nothing else. A client reading «الأعلى أداءً» over
             * three cards has no way to tell whether three is the leaders of forty or the whole of
             * what ran — and those are different reports. The roster below carries every creative,
             * which is what makes the SECTION complete; this is what makes the CLAIM honest.
             *
             * `candidates` counts the rows this family had before the ranker took its cut, not the
             * rows it returned: the ranker drops anything that did not spend, and a creative nobody
             * bought is not one a reader is missing.
             */
            'candidates' => count($rows),
            'shown' => count($ads),
        ];
    }

    /**
     * REPORT-DETAIL-PARITY-001 — the best creatives PER PLATFORM, which the report never had.
     *
     * The owner's words: «best-performing creatives per platform», not one merged top list. A single
     * list across platforms can only be ordered by something they all report, and the ads a client
     * wants to see more of are the ones that worked on the platform they ran on — «this is what works
     * on TikTok» is a finding an agency can act on, and «this is what worked overall» is not.
     *
     * Platform, and then objective INSIDE it — never platform alone. Ranking a brand ad against a
     * sales ad because they share a platform is the same defect `groupsByObjective` was written to
     * remove: one ordering across objectives can only rest on a metric they share, and what they
     * share is spend. Spending most is not performing best.
     *
     * @param  list<array<string,mixed>>  $rankable
     * @return list<array<string,mixed>>
     */
    private function groupsByPlatform(array $rankable): array
    {
        /** @var array<string, list<array<string,mixed>>> $byProvider */
        $byProvider = [];

        foreach ($rankable as $row) {
            $byProvider[(string) ($row['provider'] ?? '')][] = $row;
        }

        $platforms = [];

        foreach ($byProvider as $provider => $rows) {
            /** @var array<string, list<array<string,mixed>>> $byFamily */
            $byFamily = [];

            foreach ($rows as $row) {
                $objective = (string) ($row['objective'] ?? '');
                $family = ObjectiveFamily::tryFrom($objective)
                    ?? (CampaignObjective::tryFrom($objective)?->family() ?? ObjectiveFamily::Unknown);

                $byFamily[$family->value][] = $row;
            }

            $groups = [];

            foreach ($byFamily as $familyKey => $familyRows) {
                $groups[] = $this->familyGroup($familyKey, $familyRows);
            }

            $platforms[] = [
                'provider' => $provider,
                'groups' => $groups,
                // Every creative this platform ran, so the section can say what it is showing a part of.
                'candidates' => count($rows),
            ];
        }

        /*
         * Ordered by how much ran on each, so the platform a client spent most of their month on is
         * the first one they read. Not by spend: a platform whose spend the money contract withheld
         * would sort as nothing and land last, which is an ordering by a figure nobody can see.
         */
        usort($platforms, static fn (array $a, array $b): int => $b['candidates'] <=> $a['candidates']);

        return $platforms;
    }
}
