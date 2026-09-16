<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

/**
 * What each FORM of a report actually contains — REPORT-PRODUCT-MODEL-001, Owner defect row 96.
 *
 * ## The defect this exists to end
 *
 * The Owner opened an Executive Summary and a Detailed report over the same project and window and
 * found them effectively the same document. Measured on the live link before this class existed:
 * 43 rendered blocks against 45, 3043px of page against 3189px, 314 words against 325. The two
 * «materially different report products» differed by eleven words — which is the handoff's
 * «Detailed ≠ same dashboard + more text» violated literally.
 *
 * Two causes, and neither was a missing capability:
 *
 * 1. The form axis was enforced by two conditionals in one React component, so an executive summary
 *    was the detailed report with two blocks withheld. That is the wrong way round: a summary is not
 *    a detailed report with things hidden, it is a shorter document with its own composition.
 * 2. The one BACKEND lever that differentiated — the roster cap in {@see \App\Domains\Reports\Services\ReportAds} —
 *    read `reports.form`, while the link builder writes the operator's choice to `report_shares.form`
 *    and never touches the report row. `reports.form` is `NOT NULL DEFAULT 'detailed'`, so on every
 *    link the product itself creates that lever read «detailed» whatever the operator chose. It was
 *    not merely weak, it was permanently off.
 *
 * ## Why the rule lives here and not in the page
 *
 * `ShareSections` already states this principle for the section flags and it holds identically for
 * the form: «a section removed from the UI while its data still travels in the JSON is not a
 * permission, it is a CSS rule — and the network tab is one keystroke away». An executive summary
 * whose payload still carries the full creative roster is a detailed report wearing a shorter label,
 * and every consumer downstream — the export, the PDF, a future renderer — would have to remember
 * the trimming separately. One statement, applied where the payload is assembled, is what stops the
 * four products drifting back into one.
 *
 * ## The composition, and whose decision each line is
 *
 * The split is the Owner's, from the 2026-09-10 handoff §10, not an invention here:
 *
 *   EXECUTIVE SUMMARY — headline KPIs, period comparison, platform summary, strongest results,
 *   top content, short observations, short recommendations.
 *
 *   DETAILED — adds real analytical depth: platform breakdown, objective analysis, funnel,
 *   budget/pacing, content performance, detailed tables, trends, attribution/store, deeper evidence.
 *
 * So the funnel, the store reconciliation and the platform-specific creative rankings are the
 * DETAILED product's. The summary carries the ranked top content instead of the whole inventory —
 * and it still STATES how many creatives ran, because withholding a list is honest and going silent
 * about it is not; see the note on `ads_roster` below.
 *
 * ## What a summary deliberately KEEPS, against the instinct to trim it
 *
 * `objective_performance` — the direct-against-blended split — stays in the summary and must not be
 * removed to make it shorter. REPORT-OBJECTIVE-004 settled that with a reason this class is not
 * entitled to overturn: the summary is the version that gets forwarded and quoted, with no
 * per-platform pages behind it to argue with, so it is the one document where a blended cost per
 * order does the most damage. Trimming the section that says which figure is which would leave the
 * most-read document the least qualified one.
 *
 * Likewise the period comparison and the budget pacing stay: both are named under the Owner's own
 * Executive list, and both are decision-shaped rather than explanatory.
 */
final class ReportComposition
{
    /** The two forms a report can take. Anything else is read as `detailed`. */
    public const FORMS = ['executive_summary', 'detailed'];

    /**
     * Payload keys the DETAILED product carries and the summary does not.
     *
     * Each key is written with the EMPTY VALUE ITS OWN SHAPE TAKES — a list becomes `[]`, a block
     * becomes `null` — for the reason `applySectionFlags` records in `LiveReportService`: `[]` is
     * truthy in Javascript, so emptying `store_funnel` to a list would leave `payload.store_funnel &&`
     * true, render the block, and then read `.stages` off an array. That is a crash on a client's
     * report produced by a trimming meant to shorten it, and it was nearly shipped once already.
     *
     * Grouped by the SECTION an operator would name, because that is the unit the builder offers and
     * the unit this has to stay honest about: a form that drops a section must not leave a toggle for
     * it switched on and doing nothing.
     *
     * `ads_roster` is deliberately NOT here, and the reasoning is worth keeping because emptying it
     * was the first thing written. «ALL promoted creatives reachable» is a Detailed requirement, so a
     * summary must not print the list — but `ReportCreativeRoster` already does exactly the right
     * thing with a capped roster: it withholds the table and states the count, «this is an executive
     * summary — it shows the top performers only». Emptying the key would make `rows.length === 0`
     * true, the component would return null, and the COUNT would vanish with the table. That is
     * REPORT-CREATIVE-TRUTH-001's own defect restored — «a report showed a curated handful and said
     * nothing about the rest» — arrived at through a change meant to shorten the document. The cap
     * belongs in `ReportAds`, where it already is, and it starts working the moment the form reaches
     * it; see `LiveReportService::formFor()` for why it did not.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DETAILED_ONLY = [
        // «Funnel» and «attribution/store» — both named under the Owner's Detailed list.
        'funnel_store' => ['funnel' => [], 'store_funnel' => null],
        /*
         * Per-platform creative rankings — «platform-specific top creatives», Detailed.
         *
         * The ranked gallery above answers «what worked»; this answers «what works HERE», which is
         * the question an agency takes into next month's plan. It is depth, not a headline.
         */
        'platform_creatives' => ['ads_platform_groups' => []],
    ];

    private function __construct(public readonly string $form) {}

    /** Read a form from whatever the caller holds; anything unrecognised is the full report. */
    public static function for(?string $form): self
    {
        return new self($form === 'executive_summary' ? 'executive_summary' : 'detailed');
    }

    public function isSummary(): bool
    {
        return $this->form === 'executive_summary';
    }

    /**
     * The section names this form does NOT carry, in the vocabulary the link builder uses.
     *
     * The builder reads this to DISABLE the toggles a summary cannot honour, rather than leaving a
     * control that changes nothing — the Owner's rule is explicit that a setting which changes
     * nothing must be fixed, removed or disabled, and a placebo toggle is the worst of the three.
     *
     * @return list<string>
     */
    public function withheldSections(): array
    {
        return $this->isSummary() ? array_keys(self::DETAILED_ONLY) : [];
    }

    /**
     * Drop the blocks this form does not contain.
     *
     * Applied where the payload is assembled, so the trimming reaches the page, the export and any
     * later consumer at once instead of each remembering it. A key that is not present is left
     * alone: this removes sections, it never invents them.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        if (! $this->isSummary()) {
            return $payload;
        }

        foreach (self::DETAILED_ONLY as $keys) {
            foreach ($keys as $key => $empty) {
                if (array_key_exists($key, $payload)) {
                    $payload[$key] = $empty;
                }
            }
        }

        return $payload;
    }
}
