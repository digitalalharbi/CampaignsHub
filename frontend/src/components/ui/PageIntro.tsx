import type { ReactNode } from 'react'
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
          {eyebrow && (
            <p
              data-testid={testid ? `${testid}-eyebrow` : undefined}
              className="mb-1 truncate text-xs font-semibold uppercase tracking-wide text-text-muted"
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
      {kpis && (
        <div data-testid={testid ? `${testid}-kpis` : undefined} className="grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {kpis}
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
export function DataFreshness({ lastSyncAt, ar }: { lastSyncAt: string | null | undefined; ar: boolean }) {
  return (
    <span data-testid="data-freshness" className="inline-flex items-center gap-1">
      <RefreshCw size={12} aria-hidden />
      {ar ? 'آخر مزامنة' : 'Last sync'}:{' '}
      <span dir="ltr" className="tnum">
        {lastSyncAt ? lastSyncAt.slice(0, 16).replace('T', ' ') : ar ? 'لم تتم بعد' : 'not yet'}
      </span>
    </span>
  )
}
