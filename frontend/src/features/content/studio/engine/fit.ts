import { H, OVERSIZE, W } from './constants'

/**
 * CREATIVE-ENGINE-001 — `fit_916` from the reference, as a plan the canvas executes: never stretch. Within 30% of 9:16 the picture is
 * cover-cropped around its focus; further off it sits whole on a blurred, darkened copy of itself (blur-fill). The target is the
 * 1.12× camera canvas, and the focus is returned in its coordinates.
 */
export type FitPlan =
  | { mode: 'cover'; target: { w: number; h: number }; crop: { x: number; y: number; w: number; h: number }; focus: { x: number; y: number } }
  | { mode: 'blur'; target: { w: number; h: number }; fg: { x: number; y: number; w: number; h: number }; focus: { x: number; y: number } }

export function fitPlan(sw: number, sh: number, focus: { x: number; y: number }): FitPlan {
  const tw = Math.trunc(W * OVERSIZE)
  const th = Math.trunc(H * OVERSIZE)
  const tr = tw / th
  const sr = sw / sh

  if (Math.abs(sr - tr) / tr < 0.3) {
    let box: [number, number, number, number]
    if (sr > tr) {
      const nw = sh * tr
      const x = Math.min(Math.max(focus.x * sw - nw / 2, 0), sw - nw)
      box = [x, 0, x + nw, sh]
    } else {
      const nh = sw / tr
      const y = Math.min(Math.max(focus.y * sh - nh / 2, 0), sh - nh)
      box = [0, y, sw, y + nh]
    }

    return {
      mode: 'cover',
      target: { w: tw, h: th },
      crop: { x: box[0], y: box[1], w: box[2] - box[0], h: box[3] - box[1] },
      focus: { x: (focus.x * sw - box[0]) / (box[2] - box[0]), y: (focus.y * sh - box[1]) / (box[3] - box[1]) },
    }
  }

  const s = Math.min(tw / sw, (th * 0.62) / sh)
  const fw = Math.trunc(sw * s)
  const fh = Math.trunc(sh * s)
  const ox = Math.trunc((tw - fw) / 2)
  const oy = Math.trunc(th * 0.45 - fh / 2)

  return { mode: 'blur', target: { w: tw, h: th }, fg: { x: ox, y: oy, w: fw, h: fh }, focus: { x: (ox + focus.x * fw) / tw, y: (oy + focus.y * fh) / th } }
}
