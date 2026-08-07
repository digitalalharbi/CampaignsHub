import type { ReactNode } from 'react'
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
 */
export function PageIntro({
  title,
  purpose,
  badges,
  actions,
  meta,
  testid,
}: {
  title: string
  /** One sentence: what this category is and what it gives the reader. */
  purpose: string
  /** Demo badge, status pill — anything qualifying the title itself. */
  badges?: ReactNode
  /** The primary actions for this page, named rather than iconographic. */
  actions?: ReactNode
  /** Freshness, counts, scope — the line under the purpose. */
  meta?: ReactNode
  testid?: string
}) {
  return (
    <header data-testid={testid} className="flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <h1 className="text-2xl font-extrabold tracking-tight text-text-primary">{title}</h1>
          {badges}
        </div>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">{purpose}</p>
        {meta && <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-text-muted">{meta}</div>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
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
