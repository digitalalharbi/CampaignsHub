/**
 * CONTENT-STUDIO-001 batch 5 — easing. A cubic-bezier solved by bisection, so the previewer and the exporter evaluate the same curve at
 * the same time and a frame in the preview is the frame in the file.
 */
export type Bezier = readonly [number, number, number, number]

export const EASE_OUT: Bezier = [0.22, 1, 0.36, 1]

export const clamp01 = (v: number): number => Math.min(1, Math.max(0, v))

export function cubicBezier([x1, y1, x2, y2]: Bezier, t: number): number {
  const x = clamp01(t)
  const at = (a: number, b: number, s: number) => 3 * a * (1 - s) ** 2 * s + 3 * b * (1 - s) * s ** 2 + s ** 3
  let lo = 0
  let hi = 1

  for (let i = 0; i < 40; i++) {
    const mid = (lo + hi) / 2

    if (at(x1, x2, mid) < x) lo = mid
    else hi = mid
  }

  return at(y1, y2, (lo + hi) / 2)
}

export const easeOut = (t: number): number => cubicBezier(EASE_OUT, t)
