import type { ReactNode } from 'react'
import { useFitLock } from '@/layouts/fitContext'
import { FIT_GAP, FIT_TITLE } from '@/styles/scale'
import { InfoTip } from './InfoTip'

/**
 * NOV-FIT-001 — the frame a page wears to fit the screen.
 *
 * Title row (the one-line title, an info tooltip where the purpose paragraph used to be, the actions), then the
 * figures, then the toolbar, then the BODY — which takes whatever height is left and is the only part that gives.
 * The body is `flex-1 min-h-0`, so its height is the screen's remainder and not its contents', which is what lets
 * `useFitRows` count rows against it.
 *
 * Mounting it locks the portal frame to the viewport on a desktop (`useFitLock`); on a phone or a short window it
 * is an ordinary flowing page. Nothing about a page is dropped to fit — what does not fit moves to a tab, a pager
 * or a detail panel.
 */
export function FitPage({
  title, info, actions, kpis, toolbar, children, testid,
}: {
  title: string
  info?: string
  actions?: ReactNode
  kpis?: ReactNode
  toolbar?: ReactNode
  children: ReactNode
  testid?: string
}) {
  useFitLock()

  return (
    <section data-testid={testid} className={`flex min-h-0 flex-1 flex-col ${FIT_GAP}`}>
      <header data-testid={testid ? `${testid}-header` : undefined} className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 className={`${FIT_TITLE} min-w-0 truncate text-text-primary`}>{title}</h1>
        {info && <InfoTip text={info} label={info} />}
        {actions && <div className="ms-auto flex flex-wrap items-center gap-2">{actions}</div>}
      </header>
      {kpis}
      {toolbar}
      <div data-fit-body className="flex min-h-0 flex-1 flex-col">{children}</div>
    </section>
  )
}
