import type { Pixels } from '../analysis/pixels'
import { H, OVERSIZE, W } from './constants'
import type { FitPlan } from './fit'

/**
 * CREATIVE-ENGINE-001 — the frame as the viewer sees it, before anything is drawn on it.
 *
 * `sampleFrame` builds a small copy (W/8 × H/8) of the fitted 9:16 frame from the source pixels alone, following the fit plan and the
 * centre crop of the 1.12× camera canvas, so the layout solver reads the same picture the canvas will show: its edges (where it is
 * busy) and its brightness (what a text colour must stand against). A blur-filled frame reads its background as the darkened mean.
 */
export const SAMPLE_W = W / 8
export const SAMPLE_H = H / 8

export function sampleFrame(src: Pixels, plan: FitPlan): Pixels {
  const out = new Uint8ClampedArray(SAMPLE_W * SAMPLE_H * 4)
  const at = (x: number, y: number) => {
    const xi = Math.min(src.width - 1, Math.max(0, Math.floor(x)))
    const yi = Math.min(src.height - 1, Math.max(0, Math.floor(y)))
    const i = (yi * src.width + xi) * 4

    return [Number(src.data[i]), Number(src.data[i + 1]), Number(src.data[i + 2])] as const
  }
  let mean = [0, 0, 0]
  if (plan.mode === 'blur') {
    const n = src.width * src.height
    for (let i = 0; i < n; i++) { mean[0]! += Number(src.data[i * 4]); mean[1]! += Number(src.data[i * 4 + 1]); mean[2]! += Number(src.data[i * 4 + 2]) }
    mean = mean.map((v) => (v / Math.max(1, n)) * 0.65)
  }
  const { w: tw, h: th } = plan.target
  // The frame is the centre of the camera canvas at zoom 1.
  const offX = (tw - W) / 2
  const offY = (th - H) / 2
  const sw = src.width
  const sh = src.height
  for (let y = 0; y < SAMPLE_H; y++) {
    for (let x = 0; x < SAMPLE_W; x++) {
      const cx = offX + (x + 0.5) * 8
      const cy = offY + (y + 0.5) * 8
      let rgb: readonly number[]
      if (plan.mode === 'cover') {
        // Canvas → crop box → source pixels (the analysis copy is a scaled version of the original).
        const fx = plan.crop.x + (cx / tw) * plan.crop.w
        const fy = plan.crop.y + (cy / th) * plan.crop.h
        rgb = at((fx / plan.source.w) * sw, (fy / plan.source.h) * sh)
      } else if (cx >= plan.fg.x && cx < plan.fg.x + plan.fg.w && cy >= plan.fg.y && cy < plan.fg.y + plan.fg.h) {
        rgb = at(((cx - plan.fg.x) / plan.fg.w) * sw, ((cy - plan.fg.y) / plan.fg.h) * sh)
      } else {
        rgb = mean
      }
      out.set([rgb[0]!, rgb[1]!, rgb[2]!, 255], (y * SAMPLE_W + x) * 4)
    }
  }

  return { width: SAMPLE_W, height: SAMPLE_H, data: out }
}

export function lumaOf(p: Pixels): Float32Array {
  const out = new Float32Array(p.width * p.height)
  for (let i = 0; i < out.length; i++) out[i] = 0.2126 * Number(p.data[i * 4]) + 0.7152 * Number(p.data[i * 4 + 1]) + 0.0722 * Number(p.data[i * 4 + 2])

  return out
}

/** `edge_map` of the reference: |∂x| + |∂y| on the luma plane. */
export function edgesOf(luma: Float32Array, w: number, h: number): Float32Array {
  const e = new Float32Array(w * h)
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const i = y * w + x
    if (x > 0) e[i]! += Math.abs(luma[i]! - luma[i - 1]!)
    if (y > 0) e[i]! += Math.abs(luma[i]! - luma[i - w]!)
  }

  return e
}

/** The 80th-percentile brightness (0..255) of the frame under a box in frame pixels — the worst case a white text faces. */
export function worstLumaUnder(luma: Float32Array, box: { x: number; y: number; w: number; h: number }, pct = 0.8): number {
  const vals: number[] = []
  const x0 = Math.max(0, Math.floor(box.x / 8))
  const x1 = Math.min(SAMPLE_W, Math.ceil((box.x + box.w) / 8))
  const y0 = Math.max(0, Math.floor(box.y / 8))
  const y1 = Math.min(SAMPLE_H, Math.ceil((box.y + box.h) / 8))
  for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) vals.push(luma[y * SAMPLE_W + x]!)
  if (vals.length === 0) return 0
  vals.sort((a, b) => a - b)

  return vals[Math.min(vals.length - 1, Math.floor(vals.length * pct))]!
}

/** Unused by layout; kept so the camera canvas size is named in one place. */
export const CAMERA = { w: Math.trunc(W * OVERSIZE), h: Math.trunc(H * OVERSIZE) }
