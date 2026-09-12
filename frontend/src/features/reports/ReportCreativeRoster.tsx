import { AdPoster } from '@/features/content/AdPoster'
import { DataMetricTable, type Column, type Row } from '@/components/ui/MetricTable'
import { providerLabel } from '@/features/campaigns/labels'
import { creatives as countedCreatives } from '@/lib/counted'
import type { ReportAd } from './ReportAdsSection'
import type { CreativePreview } from '@/features/content/api'
import type { Locale } from '@/stores/ui'

/**
 * REPORT-CREATIVE-TRUTH-001 §B — what we ran, beside what worked.
 *
 * ## The two questions are not the same question
 *
 * `ReportAdsSection` above answers «which of these performed», three times over: a top list, a
 * weakest list, and a ranked group per objective. None of them answers «what did you run for me»,
 * and that is the question a client asks when they are paying for sixty-five creatives and can see
 * six. A report that only ever shows its winners is not lying about any single figure and is still
 * the wrong document.
 *
 * ## Why it is a table and not more cards
 *
 * Sixty-five posters is a gallery, and a gallery is read by browsing. This list is read by looking
 * for one thing — «is the video we shot in here, and what did it do» — so it is a table, with the
 * poster kept as a thumbnail so the row is still recognisable at a glance.
 *
 * It is `DataMetricTable`, not a hand-rolled `<table>`, which is what `metricTableContract` caught
 * when this shipped as one: seventeen surfaces once each had their own answer to where a figure sits
 * in its cell and whether a column can be ordered, and the longest list in a client's report is the
 * last place to add an eighteenth. Sorting comes with the primitive — «which cost the most» and
 * «which got the most clicks» are the two questions this list exists to be asked.
 *
 * ## The count is the honest part
 *
 * A full report reaches the whole estate and says it withheld nothing. A five-page summary presents
 * fewer on purpose — and SAYS how many it left out, rather than letting six ads read as the account.
 * Neither number is derived here: both come from the generator, which counted the scope before it
 * applied any bound.
 */
export type RosterRow = {
  id?: string
  name?: string | null
  provider?: string | null
  preview?: CreativePreview | null
  format?: string | null
  objective?: string | null
  metrics?: {
    spend?: number | null
    impressions?: number | null
    clicks?: number | null
    conversions?: number | null
    ctr?: number | null
  } | null
}

export function ReportCreativeRoster({
  roster,
  inScope,
  withheld,
  currency,
  locale,
  form,
  onOpen,
}: {
  roster: RosterRow[] | undefined
  /** How many creatives ran in this report's scope — counted before any bound was applied. */
  inScope?: number | null
  /** And how many of those this document does not list. */
  withheld?: number | null
  /** MONEY-USD-001 — the currency these figures were measured in. Null prints no currency. */
  currency?: string | null
  locale: Locale
  /** `executive_summary` states the count and withholds the list; anything else prints it. */
  form?: string | null
  /** Opens one creative's own detail, where the surface can open one. */
  onOpen?: (ad: ReportAd) => void
}) {
  const ar = locale === 'ar'
  const rows = roster ?? []
  const scope = inScope ?? 0

  /*
   * Nothing ran, so there is nothing to say here.
   *
   * The ads section above already prints WHY the window is empty, in its own words, and a second
   * heading over a second empty state would tell the reader the same absence twice.
   */
  if (scope === 0 || rows.length === 0) {
    return null
  }

  const summary = form === 'executive_summary'
  const left = Math.max(0, withheld ?? 0)

  return (
    <section data-testid="report-roster" data-state={summary ? 'counted' : 'listed'} className="mt-6 flex flex-col gap-2">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-base font-bold text-text-primary">
          {ar ? 'كل ما نُشر خلال الفترة' : 'Everything that ran'}
        </h3>
        <p data-testid="report-roster-scope" className="text-xs text-text-secondary">
          {countedCreatives(scope, locale)}
          {left > 0 && (
            <span data-testid="report-roster-withheld">
              {ar
                ? ` — معروض منها ${rows.length}، و${left} غير معروضة هنا`
                : ` — ${rows.length} listed here, ${left} not shown`}
            </span>
          )}
        </p>
      </div>

      {/*
        A summary states the count and withholds the list.

        «Reduce prose drastically» applies to a five-page deck most of all, and sixty-five rows in
        it would be the opposite. What it must not do is stay SILENT about the sixty-five, which is
        the defect this whole unit is about — so the sentence stays and only the table goes.
      */}
      {summary
        ? (
          <p data-testid="report-roster-summary-note" className="text-sm text-text-secondary">
            {ar
              ? 'هذا ملخّص تنفيذي — يعرض الأعلى أداءً فقط. القائمة الكاملة في التقرير التفصيلي.'
              : 'This is an executive summary — it shows the top performers only. The full list is in the detailed report.'}
          </p>
        )
        : (
          <DataMetricTable
            columns={columns(ar, currency ?? null)}
            rows={rows.map((row, i) => line(row, i, locale, onOpen))}
            /* Spend descending: the money is what a reader scans this list for first. */
            initialSort={{ column: 2, dir: 'desc' }}
          />
        )}
    </section>
  )
}

/**
 * The columns, in the order the question is asked: what is it, where did it run, what did it cost.
 *
 * Money carries the scope's own currency or none at all — `currency: null` prints the figure bare,
 * which is the money contract's rule rather than this table's opinion.
 */
function columns(ar: boolean, currency: string | null): Column[] {
  return [
    { key: 'creative', label: ar ? 'المادة' : 'Creative', kind: 'text' },
    { key: 'provider', label: ar ? 'المنصة' : 'Platform', kind: 'text' },
    { key: 'spend', label: ar ? 'الإنفاق' : 'Spend', kind: 'money', currency },
    { key: 'impressions', label: ar ? 'الظهور' : 'Impressions', kind: 'number' },
    { key: 'clicks', label: ar ? 'النقرات' : 'Clicks', kind: 'number' },
    { key: 'conversions', label: ar ? 'النتائج' : 'Results', kind: 'number' },
    { key: 'ctr', label: ar ? 'نسبة النقر' : 'CTR', kind: 'percent', digits: 2 },
  ]
}

/**
 * One creative's row.
 *
 * The figures are handed over RAW — the primitive formats them, and an unreported one stays `null`
 * rather than becoming a zero. «0 نقرة» on a creative nobody measured is a claim that it was seen
 * and ignored, in the one table a client scans for the thing they paid for.
 */
function line(row: RosterRow, index: number, locale: Locale, onOpen?: (ad: ReportAd) => void): Row {
  const ar = locale === 'ar'
  const m = row.metrics ?? null

  const name = (
    <div className="flex min-w-0 items-center gap-2">
      <AdPoster
        preview={row.preview ?? null}
        name={row.name ?? ''}
        className="h-9 w-12 shrink-0"
        testid={`report-roster-poster-${index}`}
      />
      <span className="truncate font-medium text-text-primary" title={row.name ?? undefined}>
        {row.name ?? '—'}
      </span>
    </div>
  )

  return {
    /*
      Openable only where the surface can open one — the printed page cannot, and a control that
      invites a press it can never answer is the defect the ad cards shipped with.
    */
    creative: onOpen === undefined
      ? <div data-testid="report-roster-row">{name}</div>
      : (
        <button
          type="button"
          data-testid="report-roster-open"
          onClick={() => onOpen(asReportAd(row))}
          aria-label={ar ? `تفاصيل ${row.name ?? ''}` : `Details for ${row.name ?? ''}`}
          className="w-full rounded-lg text-start transition-colors hover:text-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500"
        >
          <span data-testid="report-roster-row">{name}</span>
        </button>
      ),
    provider: row.provider ? providerLabel(row.provider, locale) : null,
    spend: m?.spend ?? null,
    impressions: m?.impressions ?? null,
    clicks: m?.clicks ?? null,
    conversions: m?.conversions ?? null,
    ctr: m?.ctr ?? null,
  }
}

/**
 * A presented row, in the shape the ad detail already reads.
 *
 * `CreativeRows::present()` nests the figures under `metrics`; `ReportAdDetail` reads them flat,
 * because the ranker hands it flattened rows. Flattening here is what lets ONE detail component
 * serve both lists — the alternative was a second detail that reads the other shape, which is how
 * «best ad» came to mean two things in one document.
 */
function asReportAd(row: RosterRow): ReportAd {
  const m = row.metrics ?? null

  return {
    id: row.id,
    name: row.name,
    provider: row.provider,
    objective: row.objective,
    preview: row.preview,
    spend: m?.spend ?? null,
    impressions: m?.impressions ?? null,
    clicks: m?.clicks ?? null,
    conversions: m?.conversions ?? null,
    ctr: m?.ctr ?? null,
  }
}
