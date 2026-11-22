import type { Pixels } from '../analysis/pixels'

/**
 * CREATIVE-ENGINE-001 — `saliency_center` from the reference, extended to return the product's box as well as its centre.
 *
 * Importance = (|∂x| + |∂y|) × (0.5 + saturation), weighted by a centre prior exp(-d²/0.18). The centre is the importance-weighted
 * mean. The box is the importance-weighted spread around it (±1.2σ), padded by 4%: the area no text or shape may cover. Feed it a small copy (the reference reads 96px wide).
 */
export interface Saliency { cx: number; cy: number; box: { x: number; y: number; w: number; h: number } }

export function saliency(src: Pixels): Saliency {
  const { width: W, height: H, data } = src
  const w = W - 1
  const h = H - 1
  const at = (x: number, y: number, c: number) => Number(data[(y * W + x) * 4 + c])
  const gray = (x: number, y: number) => (at(x, y, 0) + at(x, y, 1) + at(x, y, 2)) / 3
  const m = new Float64Array(w * h)
  let total = 0
  let sx = 0
  let sy = 0
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const gx = Math.abs(gray(x + 1, y) - gray(x, y))
      const gy = Math.abs(gray(x, y + 1) - gray(x, y))
      const r = at(x, y, 0)
      const g = at(x, y, 1)
      const b = at(x, y, 2)
      const mx = Math.max(r, g, b)
      const mn = Math.min(r, g, b)
      const sat = (mx - mn) / (mx + 1e-3)
      const prior = Math.exp(-(((x / w - 0.5) ** 2) + ((y / h - 0.5) ** 2)) / 0.18)
      const v = (gx + gy) * (0.5 + sat) * prior
      m[y * w + x] = v
      total += v
      sx += v * x
      sy += v * y
    }
  }
  const t = total + 1e-6
  const cx = sx / t / w
  const cy = sy / t / h

  // The box: the importance-weighted spread around the centre (±1.2σ on each axis), padded by 4%. A half-mass box of the heaviest
  // pixels spread over every busy corner of a real photograph and left no room for text; the spread follows the product itself.
  let vx = 0
  let vy = 0
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const v = m[y * w + x]!
    vx += v * (x / w - cx) ** 2
    vy += v * (y / h - cy) ** 2
  }
  const sx2 = Math.sqrt(vx / t)
  const sy2 = Math.sqrt(vy / t)
  const pad = 0.04
  const bx = Math.max(0, cx - 1.2 * sx2 - pad)
  const by = Math.max(0, cy - 1.2 * sy2 - pad)

  return { cx, cy, box: { x: bx, y: by, w: Math.min(1, cx + 1.2 * sx2 + pad) - bx, h: Math.min(1, cy + 1.2 * sy2 + pad) - by } }
}
