import { H, SAFE } from './constants'

/**
 * CREATIVE-ENGINE-001 — `wrap`, `text_layer`'s fitting loop, `choose_band`, `clamp_into_safe` and `check_safe` from the reference.
 * Text is measured by the caller (a canvas with the real font and `direction = 'rtl'`), so Arabic shaping is the browser's.
 */
export type MeasureWidth = (text: string, px: number) => number

/** Greedy wrap that refuses an orphan: a last line of one word re-wraps the whole text at 82% of the width. */
export function wrap(text: string, px: number, maxW: number, measure: MeasureWidth): string[] {
  const words = text.split(/\s+/u).filter(Boolean)
  const lines: string[] = []
  let cur = ''
  for (const w of words) {
    const t = `${cur} ${w}`.trim()
    if (measure(t, px) <= maxW || cur === '') cur = t
    else { lines.push(cur); cur = w }
  }
  if (cur) lines.push(cur)
  if (lines.length > 1 && lines[lines.length - 1]!.split(/\s+/u).length === 1 && maxW > 200) return wrap(text, px, maxW * 0.82, measure)

  return lines
}

/** At most three lines: the size steps down by 10% until it fits, never below 30px. */
export function fitLines(text: string, startPx: number, maxW: number, measure: MeasureWidth): { lines: string[]; px: number } {
  let px = startPx
  let lines = wrap(text, px, maxW, measure)
  while (lines.length > 3 && px >= 30) {
    px *= 0.9
    lines = wrap(text, px, maxW, measure)
  }

  return { lines, px }
}

/**
 * The calmest band for a block of `needH`, inside the live area and away from the product. `edges` is an edge-strength map of the
 * frame at 1/8 scale (W/8 × H/8), row-major; `focusY` is the product centre in frame pixels.
 */
export function chooseBand(edges: Float32Array | number[], cols: number, focusY: number, needH: number, prefer: 'top' | 'bottom' | 'middle'): number {
  let best: [number, number] | null = null
  for (let top = SAFE.y0 + 20; top < SAFE.y1 - needH - 10; top += 24) {
    const r0 = Math.floor(top / 8)
    const r1 = Math.floor((top + needH) / 8)
    let sum = 0
    let n = 0
    for (let r = r0; r < r1; r++) for (let c = Math.floor(SAFE.x0 / 8); c < Math.floor(SAFE.x1 / 8); c++) { sum += Number(edges[r * cols + c] ?? 0); n++ }
    const busy = n ? sum / n : 0
    const overlap = top - 60 < focusY && focusY < top + needH + 60 ? 1 : 0
    const bias = prefer === 'top' ? top / H : prefer === 'bottom' ? 1 - top / H : Math.abs(top + needH / 2 - H * 0.45) / H
    const score = busy * 0.04 + overlap * 0.8 + bias * 0.9
    if (!best || score < best[0]) best = [score, top]
  }

  return best ? best[1] : SAFE.y0 + 20
}

export interface Placed { role: string; x: number; y: number; w: number; h: number }

/** Shift each element back inside the live area horizontally, then the group vertically. Boxes are the visible ink. */
export function clampIntoSafe(items: Placed[]): Placed[] {
  if (items.length === 0) return items
  const out = items.map((p) => {
    let x = p.x
    if (x + p.w > SAFE.x1) x -= x + p.w - SAFE.x1
    if (x < SAFE.x0) x += SAFE.x0 - x

    return { ...p, x }
  })
  const y0 = Math.min(...out.map((p) => p.y))
  const y1 = Math.max(...out.map((p) => p.y + p.h))
  const dy = Math.max(SAFE.y0 - y0, 0) - Math.max(y1 - SAFE.y1, 0)

  return out.map((p) => ({ ...p, y: p.y + dy }))
}

/** Every element whose ink leaves the live area; decorative frames are the caller's to exclude. */
export function safeViolations(items: Placed[]): string[] {
  return items
    .filter((p) => p.x < SAFE.x0 || p.x + p.w > SAFE.x1 || p.y < SAFE.y0 || p.y + p.h > SAFE.y1)
    .map((p) => `${p.role} ${Math.round(p.x)},${Math.round(p.y)},${Math.round(p.x + p.w)},${Math.round(p.y + p.h)}`)
}
