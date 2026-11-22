/** CREATIVE-ENGINE-001 — colour maths ported from `docs/creative-engine/adstudio_reference.py` (rel_lum, contrast, HSV). */
export type RGB = readonly [number, number, number]

const ch = (v: number) => {
  const c = v / 255

  return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

export const relLum = ([r, g, b]: RGB): number => 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b)

export function contrast(a: RGB, b: RGB): number {
  const la = relLum(a)
  const lb = relLum(b)

  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

export const hex = ([r, g, b]: RGB): string => `#${[r, g, b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('').toUpperCase()}`

/** Python's colorsys.rgb_to_hsv on 0..1 channels: h, s, v in 0..1. */
export function rgbToHsv([r, g, b]: RGB): [number, number, number] {
  const R = r / 255
  const G = g / 255
  const B = b / 255
  const mx = Math.max(R, G, B)
  const mn = Math.min(R, G, B)
  const v = mx
  if (mx === mn) return [0, 0, v]
  const s = (mx - mn) / mx
  const rc = (mx - R) / (mx - mn)
  const gc = (mx - G) / (mx - mn)
  const bc = (mx - B) / (mx - mn)
  let h = R === mx ? bc - gc : G === mx ? 2 + rc - bc : 4 + gc - rc
  h = ((h / 6) % 1 + 1) % 1

  return [h, s, v]
}

/** Python's colorsys.hsv_to_rgb, rounded to 0..255. */
export function hsvToRgb(h: number, s: number, v: number): RGB {
  const out = (r: number, g: number, b: number): RGB => [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)]
  if (s === 0) return out(v, v, v)
  const i = Math.floor(h * 6)
  const f = h * 6 - i
  const p = v * (1 - s)
  const q = v * (1 - s * f)
  const t = v * (1 - s * (1 - f))
  switch (((i % 6) + 6) % 6) {
    case 0: return out(v, t, p)
    case 1: return out(q, v, p)
    case 2: return out(p, v, t)
    case 3: return out(p, q, v)
    case 4: return out(t, p, v)
    default: return out(v, p, q)
  }
}
