import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Pager } from './Pager'
import { useFitCompact } from './AgencyFit'
import { useFitLocked } from './useFitRows'

/**
 * AF2 — a list of fixed-height cards that pages itself to the screen.
 *
 * Inside a fitted panel (`.fit-panel`) it counts how many cards of `itemHeight` fit between its own top and the
 * panel's bottom, keeps the pager's line back, and draws exactly those; the rest is a page away. Unlocked — a phone,
 * a short window, or anywhere outside `/agency` — it draws every item and no pager, as the page always did.
 *
 * Cards are a FIXED height (`itemHeight`) and cut with an ellipsis, never wrapped, so the count is exact; whatever a
 * card does not show belongs behind its own «details» control.
 */
export function FitPaged<T>({
  items, itemHeight, gap = 10, columns = 1, render, rowKey, ar, testid, empty, listClassName = 'grid', fallback,
}: {
  items: T[]
  itemHeight: number
  gap?: number
  /** Cards across, once locked — the page holds rows × columns. Match it in `listClassName`. */
  columns?: number
  render: (item: T) => ReactNode
  rowKey: (item: T) => string
  ar: boolean
  testid: string
  empty?: ReactNode
  listClassName?: string
  /** The page's own list, drawn as it always was when the page is not fitted (a phone, a short window, `/app`). */
  fallback?: (items: T[]) => ReactNode
}) {
  const compact = useFitCompact()
  const locked = useFitLocked()
  const box = useRef<HTMLDivElement>(null)
  const [size, setSize] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const fit = compact && locked

  useEffect(() => {
    const el = box.current
    const panel = el?.closest<HTMLElement>('.fit-panel')
    if (!fit || !el || !panel) { setSize(null); return }
    let frame = 0
    const measure = () => {
      const avail = panel.getBoundingClientRect().bottom - el.getBoundingClientRect().top
      const fitAll = Math.floor((avail + gap) / (itemHeight + gap)) * columns
      const next = items.length <= fitAll ? Math.max(columns, fitAll) : Math.max(columns, Math.floor((avail - 44 + gap) / (itemHeight + gap)) * columns)
      setSize((cur) => (cur === next ? cur : next))
    }
    const ro = new ResizeObserver(() => { cancelAnimationFrame(frame); frame = requestAnimationFrame(measure) })
    ro.observe(panel)
    measure()

    return () => { ro.disconnect(); cancelAnimationFrame(frame) }
  }, [fit, items.length, itemHeight, gap, columns])

  const pages = fit && size ? Math.max(1, Math.ceil(items.length / size)) : 1
  const current = Math.min(page, pages)
  useEffect(() => { if (page !== current) setPage(current) }, [page, current])
  const shown = fit && size ? items.slice((current - 1) * size, current * size) : items

  if (!fit && fallback) return <>{fallback(items)}</>
  if (items.length === 0 && empty) return <div data-testid={`${testid}-empty`}>{empty}</div>

  return (
    <div ref={box} className={fit ? 'flex min-h-0 flex-col' : undefined} data-testid={testid}>
      <ul className={listClassName} style={fit ? { gap } : undefined}>
        {shown.map((item) => <li key={rowKey(item)} className="min-w-0" style={fit ? { height: itemHeight } : undefined}>{render(item)}</li>)}
      </ul>
      {fit && pages > 1 && <Pager page={current} pages={pages} onPage={setPage} total={items.length} ar={ar} testid={`${testid}-pager`} />}
    </div>
  )
}
