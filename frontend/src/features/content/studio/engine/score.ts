import { H, MIN_CONTRAST, W } from './constants'
import type { Box, El } from './solve'

/**
 * CREATIVE-ENGINE-001 — the aesthetic score (0..100), rules not taste. Five parts of twenty: white space (text covers 8–22% of the
 * frame), hierarchy (headline ≥ 1.8× the sub-line), contrast headroom above 4.5:1, balance (the text's weight sits away from the
 * product), and product visibility (nothing opaque over it).
 */
const area = (b: Box) => b.w * b.h

export function score(els: El[], product: Box, minContrast: number): number {
  const texts = els.filter((e) => e.lines)
  const ratio = texts.reduce((n, e) => n + area(e.box), 0) / (W * H)
  const white = ratio >= 0.08 && ratio <= 0.22 ? 20 : Math.max(0, 20 - Math.min(Math.abs(ratio - 0.08), Math.abs(ratio - 0.22)) * 200)
  const hook = els.find((e) => e.role === 'hook')?.px ?? 0
  const sub = els.find((e) => e.role === 'message')?.px ?? 0
  const hierarchy = sub === 0 ? 14 : Math.min(20, (hook / sub / 1.8) * 20)
  const headroom = Math.min(20, Math.max(0, ((minContrast - MIN_CONTRAST) / MIN_CONTRAST) * 20 + 8))
  const cy = texts.reduce((n, e) => n + (e.box.y + e.box.h / 2) * area(e.box), 0) / Math.max(1, texts.reduce((n, e) => n + area(e.box), 0))
  const balance = Math.min(20, (Math.abs(cy - (product.y + product.h / 2)) / (H * 0.4)) * 20)
  const opaque = els.filter((e) => e.fill && !e.fill.startsWith('rgba') && !e.lines).reduce((n, e) => {
    const ix = Math.max(0, Math.min(e.box.x + e.box.w, product.x + product.w) - Math.max(e.box.x, product.x))
    const iy = Math.max(0, Math.min(e.box.y + e.box.h, product.y + product.h) - Math.max(e.box.y, product.y))

    return n + ix * iy
  }, 0)
  const visible = 20 * (1 - Math.min(1, opaque / Math.max(1, area(product))))

  return Math.round(white + hierarchy + headroom + balance + visible)
}
