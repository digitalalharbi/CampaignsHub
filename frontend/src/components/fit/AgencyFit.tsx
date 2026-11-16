import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import { useInRouterContext, useLocation } from 'react-router-dom'
import { useFitLock } from '@/layouts/fitContext'
import { FIT_GAP } from '@/styles/scale'
import { roomBelow, useFitLocked } from './useFitRows'

/**
 * AF2 (NOV-FIT-001, second pass) — the frame a page SHARED with `/app` wears, on `/agency` only.
 *
 * Several operator pages are the same engine the advertiser portal mounts, and `/app` specs assert their layout. So
 * the fit is opt-in by portal: under `/agency` the page locks to the viewport and its headers and figures turn
 * compact (`useFitCompact`); anywhere else it renders exactly the wrapper it always had.
 *
 * Nothing is removed to make a page fit. What does not fit moves to a tab, a pager or a tooltip.
 */
const FitCompactContext = createContext(false)

/** Lets a layer (a detail drawer) draw the page's FULL form again: it is not budgeted against the screen. */
export const FitCompactProvider = FitCompactContext.Provider

/** True inside an `/agency` page that wears the frame — `PageIntro` and `StatCard` draw their compact forms. */
export function useFitCompact(): boolean {
  return useContext(FitCompactContext)
}

function Locked({ children, testid }: { children: ReactNode; testid?: string }) {
  useFitLock(true)

  return (
    <FitCompactContext.Provider value>
      <section data-testid={testid} className={`flex min-h-0 flex-1 flex-col ${FIT_GAP}`}>{children}</section>
    </FitCompactContext.Provider>
  )
}

function Routed({ children, className, testid, active }: { children: ReactNode; className: string; testid?: string; active: boolean }) {
  const { pathname } = useLocation()
  if (active && pathname.startsWith('/agency')) return <Locked testid={testid}>{children}</Locked>

  return <div data-testid={testid} className={className}>{children}</div>
}

export function AgencyFit({ children, className = 'space-y-6', testid, active = true }: { children: ReactNode; className?: string; testid?: string; active?: boolean }) {
  const inRouter = useInRouterContext()
  if (!inRouter) return <div data-testid={testid} className={className}>{children}</div>

  return <Routed className={className} testid={testid} active={active}>{children}</Routed>
}

/** The part of a fitted page that gives: it takes the height that is left, and everything inside is budgeted against it. */
export function FitPanel({ children, className = '', testid }: { children: ReactNode; className?: string; testid?: string }) {
  return <div data-testid={testid} data-fit-panel className={`fit-panel ${className}`}>{children}</div>
}

/**
 * The height of the box this ref is on, while the page is locked — so a chart can take what is left rather than a
 * guess. `null` when unlocked (a phone, a short window), where charts keep their own fixed height and the page flows.
 */
export function useBoxHeight(enabled?: boolean): { ref: (el: HTMLElement | null) => void; height: number | null } {
  const locked = useFitLocked()
  const ctx = useFitCompact()
  const compact = enabled ?? ctx
  const node = useRef<HTMLElement | null>(null)
  const [h, setH] = useState<number | null>(null)
  const [el, setEl] = useState<HTMLElement | null>(null)

  useEffect(() => {
    if (!el || !locked || !compact) { setH(null); return }
    let frame = 0
    const measure = () => setH((cur) => { const next = Math.floor(el.clientHeight); return cur === next ? cur : next })
    const ro = new ResizeObserver(() => { cancelAnimationFrame(frame); frame = requestAnimationFrame(measure) })
    ro.observe(el)
    measure()

    return () => { ro.disconnect(); cancelAnimationFrame(frame) }
  }, [el, locked, compact])

  return { ref: (n) => { node.current = n; setEl(n) }, height: locked && compact ? h : null }
}

/**
 * How many rows of a plain `<table>` fit between the table's top and the bottom of its `.fit-panel`, read from the
 * rows as drawn (the tallest of the first twelve, so a wrapped cell cannot push the last row off the screen).
 *
 * `undefined` means «draw every row» — unlocked, outside a panel, or when everything already fits. The pager's line is
 * kept back only when paging is actually needed.
 */
export function useMeasuredPage(rowCount: number): { ref: (el: HTMLElement | null) => void; size: number | undefined } {
  const locked = useFitLocked()
  const compact = useFitCompact()
  const [el, setEl] = useState<HTMLElement | null>(null)
  const [size, setSize] = useState<number | undefined>(undefined)
  const seen = useRef(0)
  /* Whether a pager is drawn below the table now — then it is already inside `roomBelow`, and no line is kept back twice. */
  const paging = useRef(false)
  const on = locked && compact

  useEffect(() => {
    const panel = el?.closest<HTMLElement>('.fit-panel')
    if (!on || !el || !panel) { setSize(undefined); return }
    let frame = 0
    seen.current = 0
    const measure = () => {
      const rows = [...el.querySelectorAll<HTMLElement>('tbody tr')]
      const head = el.querySelector<HTMLElement>('thead')?.offsetHeight ?? 36
      seen.current = Math.max(seen.current, 36, ...rows.slice(0, 12).map((r) => r.offsetHeight))
      const rowH = seen.current
      /* Everything below the table already drawn (the pager too, once paging) is room the rows cannot use. */
      const avail = panel.getBoundingClientRect().bottom - el.getBoundingClientRect().top - head - roomBelow(el, panel)
      const all = Math.max(1, Math.floor(avail / rowH))
      const next = rowCount <= all ? undefined : Math.max(1, Math.floor((avail - (paging.current ? 0 : 44)) / rowH))
      paging.current = next !== undefined
      setSize((cur) => (cur === next ? cur : next))
    }
    const ro = new ResizeObserver(() => { cancelAnimationFrame(frame); frame = requestAnimationFrame(measure) })
    ro.observe(panel)
    ro.observe(el)
    measure()

    return () => { ro.disconnect(); cancelAnimationFrame(frame) }
  }, [on, el, rowCount])

  return { ref: setEl, size }
}
