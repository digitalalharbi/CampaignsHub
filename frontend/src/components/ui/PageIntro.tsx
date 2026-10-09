import type { ReactNode } from 'react'
import { StatGrid } from '@/components/ui/StatCard'
import { PAGE_TITLE } from '@/styles/scale'
import { RefreshCw } from 'lucide-react'

/**
 * What this page is for, said on the page — UX-IDENTITY-001.
 *
 * Every category in this product answers a different question, and a heading alone does not say
 * which: «التقارير» could be reports you send, reports you received, or a report builder. One
 * sentence under the title costs a line and removes the first thirty seconds of every reader's
 * visit. It is not marketing copy — it names what the reader can DO here.
 *
 * `actions` sit on the same line as the title on a wide screen and wrap under it on a phone. The
 * wrapping is load-bearing rather than cosmetic: a header row that does not wrap is the single most
 * common cause of a page that scrolls sideways, and content reachable only by dragging is content a
 * phone user will not find.
 *
 * ## The hero layer — UX-PAGE-HERO-001
 *
 * Ninety-two surfaces drew their own `<h1>` and five used this. So the product's own header was the
 * minority spelling, and every page that opted out also opted out of the purpose line, the badge row
 * and the wrapping rule above.
 *
 * Three additions, all optional, so every existing adopter gains them without being touched:
 *
 * - `eyebrow` — the scope this page is showing, ABOVE the title. A reader arriving at «التقارير» cannot
 *   tell whether they are looking at one project or the whole portfolio, and the answer was previously
 *   a sentence somewhere below the fold or nowhere at all.
 * - `kpis` — the figures the page is actually about, IN the header. A page that leads with prose makes
 *   its reader read to find out how they are doing; a page that leads with numbers answers first.
 * - `purpose` is now optional. It was required, so a surface with nothing useful to say wrote something
 *   anyway — and filler under a title is worse than a title alone.
 */
export function PageIntro({
  title,
  purpose,
  eyebrow,
  badges,
  actions,
  kpis,
  meta,
  testid,
}: {
  title: string
  /**
   * One sentence: what this category is and what it gives the reader.
   *
   * Optional, and deliberately so — see the hero note above. Say nothing rather than fill the line.
   */
  purpose?: string
  /** The scope this page is showing — a project, the portfolio, a client. Above the title. */
  eyebrow?: ReactNode
  /** The figures this page is about, beside the title rather than below the fold. */
  kpis?: ReactNode
  /** Demo badge, status pill — anything qualifying the title itself. */
  badges?: ReactNode
  /** The primary actions for this page, named rather than iconographic. */
  actions?: ReactNode
  /** Freshness, counts, scope — the line under the purpose. */
  meta?: ReactNode
  testid?: string
}) {
  return (
    <header data-testid={testid} className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          {/*
            The row is reserved from the first paint, not grown when the name arrives.

            A project-scoped page learns its project's NAME from a query, so `eyebrow` was
            `undefined` for the first few hundred milliseconds and this line did not exist. When the
            name landed the header grew 23px and every control under it dropped a line — which on
            `/agency/content` is measured: the view toggle moved 22px after the options loaded, and a
            reader aiming at «قائمة» on a page that has just settled hits whatever took its place.

            So the test is `!== undefined` rather than truthy, and the line carries a `min-h`: a page
            that HAS an eyebrow passes `''` while it is still finding out, keeps its height, and fills
            the same line in place. A page with no eyebrow at all passes nothing and loses the row,
            exactly as before.
          */}
          {eyebrow !== undefined && (
            <p
              data-testid={testid ? `${testid}-eyebrow` : undefined}
              className="mb-1 min-h-[1.25rem] truncate text-xs font-semibold uppercase leading-5 tracking-wide text-text-muted"
            >
              {eyebrow}
            </p>
          )}
          <div className="flex flex-wrap items-center gap-2">
            <h1 className={`text-text-primary ${PAGE_TITLE}`}>{title}</h1>
            {badges}
          </div>
          {purpose && <p className="mt-1.5 max-w-2xl text-sm leading-relaxed text-text-secondary">{purpose}</p>}
          {meta && <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-text-muted">{meta}</div>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>

      {/*
        The figures, in the header — and `min-w-0` on the grid cells rather than a fixed width.
        A KPI row that cannot shrink is the other common cause of a page that scrolls sideways, and
        this one sits above the fold where that is most visible.
      */}
      {/*
        The shared grid — Owner directive 2026-10-09 §8. These rows never carry a sparkline, so the
        cards reserve no row for one; a page-local grid kept them outside that decision and every
        hero KPI stood 174 px tall for 36 px of nothing. The page rhythm (two by two, then four) stays.
      */}
      {kpis && (
        <div data-testid={testid ? `${testid}-kpis` : undefined} className="min-w-0">
          <StatGrid columns="sm:grid-cols-2 lg:grid-cols-4">{kpis}</StatGrid>
        </div>
      )}
    </header>
  )
}

/**
 * How old the figures are, beside the figures.
 *
 * §15.15's rule at the page level: a dashboard that cannot say when it last synced is a dashboard
 * asking to be trusted on nothing. «لم تتم بعد» is a real answer and is shown as one — an empty
 * space here reads as «just now», which is the one thing it never means.
 */
/**
 * The product's staleness threshold, in hours — `DataFreshnessService::STALE_AFTER_HOURS` and
 * `ProjectListSummary::STALE_AFTER_HOURS`, which agree with each other and now with the browser.
 *
 * Stated once here rather than at each call site: a page whose badge says «محدّثة» over a project
 * the server has already marked `stale` is two rulebooks disagreeing in front of the reader.
 */
export const STALE_AFTER_HOURS = 48

export function DataFreshness({ lastSyncAt, ar, staleAfterHours, testid = 'data-freshness' }: {
  lastSyncAt: string | null | undefined
  ar: boolean
  /**
   * PRODUCT-VISUAL-001 §4 — say the STATE, not only the timestamp.
   *
   * «آخر مزامنة: 2026-09-28» asks every reader to do the arithmetic and decide for themselves
   * whether that is fine, and they will answer differently. With a threshold the product does the
   * deciding once and says the word — so «متأخرة» means the same thing on every surface, which is
   * the whole point of a shared component saying it.
   *
   * Absent, the timestamp is shown alone, exactly as before: a surface with no honest threshold
   * must not invent one, and a wrong «up to date» is worse than no verdict.
   *
   * {@link STALE_AFTER_HOURS} is the product's own number and is what callers should pass. It is 48
   * rather than 24 for the reason the server gives: the platforms restate the previous day for
   * hours and several sweep on a multi-hour cron, so a badge flipping to «متأخرة» every morning
   * before the first sweep would be wrong more often than right.
   */
  staleAfterHours?: number
  testid?: string
}) {
  const state = lastSyncAt === null || lastSyncAt === undefined
    ? 'never'
    : staleAfterHours === undefined
      ? 'unknown'
      : (Date.now() - new Date(lastSyncAt).getTime()) / 3_600_000 > staleAfterHours
        ? 'stale'
        : 'fresh'

  const word = state === 'never'
    ? (ar ? 'لم تصل بيانات بعد' : 'No data yet')
    : state === 'stale'
      ? (ar ? 'البيانات متأخرة' : 'Data is behind')
      : state === 'fresh'
        ? (ar ? 'البيانات محدّثة' : 'Up to date')
        : null

  return (
    <span data-testid={testid} data-state={state} className="inline-flex items-center gap-1">
      <RefreshCw size={12} aria-hidden />
      {word !== null && <span className={state === 'stale' ? 'font-semibold text-warning' : undefined}>{word}</span>}
      {state !== 'never' && (
        <>
          {word !== null && <span aria-hidden>·</span>}
          <span dir="ltr" className="tnum">
            {(lastSyncAt as string).slice(0, 16).replace('T', ' ')}
          </span>
        </>
      )}
      {word === null && state === 'never' && (
        <span dir="ltr" className="tnum">{ar ? 'لم تتم بعد' : 'not yet'}</span>
      )}
    </span>
  )
}
