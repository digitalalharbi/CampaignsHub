import { brand } from '@/lib/brand'
import type { Locale } from '@/stores/ui'

/**
 * The watermark a share asks for — drawn once, so the page and the file cannot disagree.
 *
 * ## The defect this exists to end
 *
 * `report_shares.watermark` was validated, stored, returned in the share payload, drawn by
 * `PublicReport`, and rendered to the client in their own portal as a badge reading «يحمل علامة
 * مائية / Watermarked». The PDF carried none.
 *
 * That absence was STRUCTURAL rather than data-dependent, which is why no render could have been
 * unlucky enough to miss it: `PublicReportController::download` holds the share and applies
 * `allow_download`, `hide_spend`, `hide_revenue` and `hide_campaign_names` — and never `watermark`;
 * `ChromiumPdfRenderer` built its print URL with `type` and `theme` alone; and neither print
 * renderer contained the word. There was no path by which a watermark could reach the file.
 *
 * The PDF is the distributable artefact — the one place a watermark is FOR. A link with both
 * `watermark` and `allow_download` told a client their document was watermarked and handed them a
 * clean one, which is a false claim about a document they keep and forward.
 *
 * ## Why one component
 *
 * Two surfaces draw this, and a watermark whose angle, weight or opacity differs between the page
 * and the file is an attribution mark that identifies which surface produced it rather than whose
 * report it is. The same reasoning `CreativeTrend` records for the modal and the detail page: the
 * second implementation is the one that drifts.
 *
 * XLSX and CSV are deliberately out of scope — a spreadsheet has no page to mark, and the export
 * manifest already carries the attribution.
 */
export function ReportWatermark({ locale, mode = 'page' }: {
  locale: Locale
  /**
   * How the mark is positioned — and the two layouts genuinely need different mechanisms.
   *
   * `page` is for a surface made of PAGE-SIZED sections: the slide deck prints one `.report-slide`
   * per sheet, each already `position: relative`, so an absolute overlay lands exactly on its own
   * page and every page gets one.
   *
   * `flow` is for a CONTINUOUS document that Chromium paginates itself — there is no per-page
   * element to attach to, so an absolute overlay would mark the first sheet and nothing after it.
   * A fixed overlay is repeated by the print engine on every page, which is the only way to reach
   * sheet four of a document whose sheets do not exist until it is printed.
   *
   * Both were MEASURED through the real renderer rather than reasoned about, reading the delivered
   * bytes back with Apple PDFKit — a different tool from the pikepdf that normalises them, so the
   * check is not the fixer marking its own work. Deck: 15 pages, all 15 bearing the mark, against 1
   * with the flag off (the cover credit BRAND-ATTRIBUTION-001 puts there once). Document: 4 pages,
   * all 4 bearing it.
   *
   * One difference worth recording so nobody reads it as a fault. In the deck the mark extracts as a
   * contiguous string; in the document layout it extracts with spacing between glyph runs, so a
   * search for the whole name does not match even though every page carries it — found by diffing
   * the two PDFs character by character rather than trusting a `contains`. That is a text-layer
   * extraction artefact and not a rendering one, and it costs a watermark nothing: its job is visual
   * deterrence and attribution, not searchability.
   *
   * This is one component with two strategies rather than two components, because what must not
   * drift is the MARK — its wording, angle, weight and opacity. Where it is anchored is a fact about
   * the layout; what it says is a fact about the document.
   */
  mode?: 'page' | 'flow'
}) {
  return (
    <div
      aria-hidden
      className={`pointer-events-none ${mode === 'flow' ? 'fixed' : 'absolute'} inset-0 z-0 flex items-center justify-center overflow-hidden`}
    >
      {/*
        BRAND-CANONICAL-001 — the watermark says the platform's name in the READER's language.

        It was the Latin wordmark, hardcoded, on a document whose every other word is Arabic for
        most of the clients who hold one.
      */}
      <span className="rotate-[-25deg] text-[80px] font-extrabold text-text-primary/5">
        {locale === 'ar' ? brand.lockup.nameAr : brand.lockup.nameEn}
      </span>
    </div>
  )
}
