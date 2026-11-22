import { useEffect, useRef } from 'react'
import { H, W } from './constants'
import type { Genome } from './genome'
import { DEFAULT_DURATION_MS, paint, type PaintOptions } from './paint'
import type { El } from './solve'

/**
 * CREATIVE-ENGINE-001 — a candidate on a real canvas at its real size, drawn by the same `paint` that renders the saved file. A film
 * plays only while it is on screen; a still is drawn once.
 */
export function EnginePreview({ elements, genome, options, video, locale }: { elements: El[]; genome: Genome; options: PaintOptions; video: boolean; locale: 'ar' | 'en' }) {
  const ref = useRef<HTMLCanvasElement | null>(null)
  const visible = useRef(true)

  useEffect(() => {
    const canvas = ref.current
    const ctx = canvas?.getContext('2d')
    if (!canvas || !ctx) return
    if (!video) { paint(ctx, elements, genome, options, null, DEFAULT_DURATION_MS, locale); return }

    const observer = new IntersectionObserver(([entry]) => { visible.current = entry?.isIntersecting ?? true })
    observer.observe(canvas)
    let frame = 0
    let start: number | null = null
    const tick = (now: number) => {
      start ??= now
      if (visible.current) paint(ctx, elements, genome, options, (now - start) % DEFAULT_DURATION_MS, DEFAULT_DURATION_MS, locale)
      frame = requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)

    return () => { cancelAnimationFrame(frame); observer.disconnect() }
  }, [elements, genome, options, video, locale])

  return <canvas ref={ref} width={W} height={H} className="h-auto w-full rounded-xl bg-surface-secondary" data-testid="engine-preview" />
}
