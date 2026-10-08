import { ChartCard, RankingBarChart } from '@/features/analytics/charts'
import { MetricTable } from '@/components/ui/MetricTable'

/**
 * REPORT-LINK-SECTION-001 — the addresses the money pointed at.
 *
 * A short link is the one artefact of a campaign that LEAVES this product: it is pasted into an ad,
 * read aloud, sent in a message. A report could describe a month of spend and never name one, while
 * the clicks were counted the whole time on a page nobody opens while reading a report.
 *
 * ## Two counts that are never added together
 *
 * `follows` is what was measured INSIDE this report's window — the figure the document is entitled
 * to speak about. `clicks_all_time` is the link's lifetime counter, carried for context so a reader
 * can see that a link with four follows this month has been followed four hundred times since it
 * was made. Summing them would count a year of clicks into a month, so they sit in separate columns
 * and the ranking is drawn from the window figure alone.
 *
 * ## The absent states are the generator's to name
 *
 * «There are no short links in this scope» and «this window closed before any follow was timed» are
 * different facts about very different situations, and only the service that looked knows which
 * applies — see SHORT-LINK-HOPS-001 for why the second exists at all. This component prints the
 * reason it was handed and never composes one.
 */
export interface ReportLinkRow {
  slug: string
  short_url: string
  destination: string
  follows: number
  clicks_all_time: number
  is_active: boolean
}

export function ReportLinksSection({
  links,
  absentReason,
  recordingSince,
  locale,
}: {
  links?: ReportLinkRow[]
  /** The generator's own code — rendered by the caller's outline; this only decides whether to draw. */
  absentReason?: string | null
  recordingSince?: string | null
  locale: 'ar' | 'en'
}) {
  const ar = locale === 'ar'
  const rows = links ?? []

  if (rows.length === 0) {
    return (
      <p data-testid="report-links-absent" className="rounded-xl border border-dashed border-border px-4 py-5 text-sm text-text-secondary">
        {absentReason === 'links_not_recorded_in_this_window'
          ? (ar
            ? 'الروابط موجودة، لكن لم يُسجَّل وقت أي متابعة قبل نهاية هذه الفترة — فلا أرقام لهذه الفترة، وليست صفرًا.'
            : 'The links exist, but no follow had been timed before this period ended — so there are no figures for it, which is not the same as zero.')
          : (ar
            ? 'لا روابط مختصرة ضمن نطاق هذا التقرير.'
            : 'There are no short links in this report’s scope.')}
      </p>
    )
  }

  /*
    Ranked on the WINDOW's follows, which is the only figure this report measured. Drawn only where
    something was actually followed: bars of equal length at zero imply a comparison nobody can make,
    and a measured-but-quiet period is better said in the table than drawn as a flat chart.
  */
  const anyFollowed = rows.some((l) => l.follows > 0)

  return (
    <div className="flex flex-col gap-4" data-testid="report-links-section">
      {/*
        TABLE-SORT-ALIGN-001 — the product's one analytical table, not a twelfth hand-rolled one.

        The first version of this section wrote its own `<table>` with `text-end` numeric cells and
        `dir="ltr"` on the cells themselves. Three separate contract tests caught it at once, and
        they were right on every count: under `dir="rtl"` an end-aligned figure sits against the LEFT
        edge of its column, as far from its Arabic heading as the column is wide, and a `dir` on a
        block box moves the whole box rather than the numeral inside it. `MetricTable` already
        settles all of that, and sorts.
      */}
      <MetricTable
        head={[
          ar ? 'الرابط' : 'Link',
          ar ? 'الوجهة' : 'Destination',
          ar ? 'متابعات الفترة' : 'Follows this period',
          ar ? 'الإجمالي منذ الإنشاء' : 'All time',
        ]}
        rows={rows.map((l) => [
          <span key="u" data-testid={`report-link-${l.slug}`} className="ltr-figure">{l.short_url}</span>,
          <span key="d" className="ltr-figure" title={l.destination}>{l.destination}</span>,
          l.follows,
          /* Dimmer, because it is context rather than this period's result. */
          <span key="a" className="text-text-secondary">{l.clicks_all_time.toLocaleString('en-US')}</span>,
        ])}
        values={rows.map((l) => [l.short_url, l.destination, l.follows, l.clicks_all_time])}
        initialSort={{ column: 2, dir: 'desc' }}
      />

      {anyFollowed && rows.length > 1 && (
        <ChartCard
          title={ar ? 'الروابط الأكثر متابعة في هذه الفترة' : 'The most followed links this period'}
          subtitle={ar ? 'متابعات مُسجَّلة داخل فترة التقرير.' : 'Follows recorded inside the report’s window.'}
        >
          <div data-testid="report-links-chart" className="min-w-0">
            <RankingBarChart
              horizontal
              height={Math.max(140, Math.min(rows.length, 8) * 38)}
              data={[...rows]
                .sort((a, b) => b.follows - a.follows)
                .slice(0, 8)
                .map((l) => ({ label: l.slug, follows: l.follows }))}
              bars={[{ key: 'follows', name: ar ? 'متابعة' : 'follows', kind: 'num' }]}
            />
          </div>
        </ChartCard>
      )}

      {/*
        Where the counting begins. Without it a reader comparing «4 this period» with «400 all time»
        has no way to tell whether the period was quiet or simply earlier than the measurement.
      */}
      {recordingSince !== null && recordingSince !== undefined && (
        <p data-testid="report-links-since" className="text-xs text-text-muted">
          {ar
            ? `تسجيل أوقات المتابعة يبدأ من ${new Date(recordingSince).toLocaleDateString('en-CA')} — ما قبله غير مُسجَّل، وليس صفرًا.`
            : `Follows have been timed since ${new Date(recordingSince).toLocaleDateString('en-CA')} — before that is unrecorded, not zero.`}
        </p>
      )}
    </div>
  )
}
