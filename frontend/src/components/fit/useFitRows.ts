import { useCallback, useEffect, useRef, useState } from 'react'
import { FIT_HEAD_HEIGHT, FIT_LOCK_QUERY, FIT_PAGER_HEIGHT, FIT_ROW_HEIGHT } from '@/styles/scale'

/** True while the page is locked to the screen (`FIT_LOCK_QUERY`) — and only then is a measured row count meaningful. */
export function useFitLocked(): boolean {
  const query = () => (typeof window !== 'undefined' && typeof window.matchMedia === 'function' ? window.matchMedia(FIT_LOCK_QUERY) : null)
  const [locked, setLocked] = useState(() => query()?.matches ?? false)

  useEffect(() => {
    const m = query()
    if (!m) return
    const on = () => setLocked(m.matches)
    on()
    m.addEventListener('change', on)

    return () => m.removeEventListener('change', on)
  }, [])

  return locked
}

/**
 * NOV-FIT-001 — how many table rows fit in the box this ref is attached to.
 *
 * The box is `flex-1 min-h-0` inside a locked page, so its height does not depend on its contents and measuring it
 * cannot feed back on itself. Not locked (a phone, a short window), there is nothing to fit into: the page flows,
 * and a fixed `fallback` count is returned instead of a measurement that would only chase its own tail.
 *
 * Whole rows only (`floor`), clamped to `[min, max]`, and re-measured on resize through a `ResizeObserver` held to
 * one update per frame.
 */
export function useFitRows({
  rowHeight = FIT_ROW_HEIGHT,
  reserve = FIT_HEAD_HEIGHT + FIT_PAGER_HEIGHT,
  min = 3,
  max = 50,
  fallback = 10,
}: { rowHeight?: number; reserve?: number; min?: number; max?: number; fallback?: number } = {}) {
  const locked = useFitLocked()
  const [rows, setRows] = useState(fallback)
  const [measured, setMeasured] = useState(false)
  const node = useRef<HTMLElement | null>(null)
  const frame = useRef(0)

  const measure = useCallback(() => {
    const el = node.current
    if (!el) return
    const next = Math.max(min, Math.min(max, Math.floor((el.clientHeight - reserve) / rowHeight)))
    setRows((current) => (current === next ? current : next))
    setMeasured(true)
  }, [min, max, reserve, rowHeight])

  const ref = useCallback((el: HTMLElement | null) => {
    node.current = el
    if (el && locked) measure()
  }, [locked, measure])

  useEffect(() => {
    const el = node.current
    if (!locked || !el || typeof ResizeObserver === 'undefined') {
      if (!locked) setRows(fallback)

      return
    }
    const observer = new ResizeObserver(() => {
      cancelAnimationFrame(frame.current)
      frame.current = requestAnimationFrame(measure)
    })
    observer.observe(el)
    measure()

    return () => { observer.disconnect(); cancelAnimationFrame(frame.current) }
  }, [locked, measure, fallback])

  /* `measured`: the box has been read at least once — a caller that asks the server for `rows` waits for it. */
  return { ref, rows: locked ? rows : fallback, locked, measured: locked ? measured : true }
}

/** `min-width: 768px` — the same `md` Tailwind uses, read in JS so only ONE of the two shapes is in the document. */
export function useIsTablet(): boolean {
  const query = () => (typeof window !== 'undefined' && typeof window.matchMedia === 'function' ? window.matchMedia('(min-width: 768px)') : null)
  const [wide, setWide] = useState(() => query()?.matches ?? true)

  useEffect(() => {
    const m = query()
    if (!m) return
    const on = () => setWide(m.matches)
    on()
    m.addEventListener('change', on)

    return () => m.removeEventListener('change', on)
  }, [])

  return wide
}

/**
 * What the fit panel draws BELOW `el` — a card's bottom padding and border, a note, a pager drawn outside the table — as
 * laid out now. The paging used to keep back a fixed pager line only, so a card's own padding was paid for by the slack
 * of shorter rows; with fonts that set every row at its tallest (Linux CI) the last row hung 7px below the content box
 * (agency-fit-2 /agency/ads, CI run 43810966817). Read from the panel's children, which size to their content.
 */
export function roomBelow(el: HTMLElement, panel: HTMLElement): number {
  const bottoms = [...panel.children].map((c) => c.getBoundingClientRect().bottom)
  if (bottoms.length === 0) return 0

  return Math.max(0, Math.max(...bottoms) - el.getBoundingClientRect().bottom)
}
