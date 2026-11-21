/**
 * CONTENT-STUDIO-001 batch 3 — WCAG contrast, and the smallest nudge that makes a colour readable on another.
 * Colours are `[r, g, b]` in 0–255.
 */
export type RGB = readonly [number, number, number]

const channel = (v: number): number => {
  const s = v / 255

  return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
}

export const relativeLuminance = (c: RGB): number => 0.2126 * channel(c[0]) + 0.7152 * channel(c[1]) + 0.0722 * channel(c[2])

export function contrastRatio(a: RGB, b: RGB): number {
  const la = relativeLuminance(a)
  const lb = relativeLuminance(b)

  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

const mix = (a: RGB, b: RGB, t: number): [number, number, number] => [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t]

export const toHex = (c: RGB): string => `#${c.map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('')}`

export function fromHex(hex: string): RGB {
  const h = hex.replace('#', '')
  const n = h.length === 3 ? h.split('').map((x) => x + x).join('') : h

  return [parseInt(n.slice(0, 2), 16), parseInt(n.slice(2, 4), 16), parseInt(n.slice(4, 6), 16)]
}

/**
 * The colour closest to `fg` that reaches `min` contrast on `bg`: `fg` itself when it already does, otherwise `fg` pulled toward white
 * on a dark ground or toward black on a light one, one small step at a time so its hue survives as long as possible.
 */
export function ensureContrast(fg: RGB, bg: RGB, min = 4.5): [number, number, number] {
  if (contrastRatio(fg, bg) >= min) return [fg[0], fg[1], fg[2]]

  const target: RGB = relativeLuminance(bg) < 0.4 ? [255, 255, 255] : [0, 0, 0]

  for (let step = 1; step <= 100; step++) {
    const c = mix(fg, target, step / 100)

    if (contrastRatio(c, bg) >= min) return c
  }

  return [target[0], target[1], target[2]]
}

/** Whichever of near-white and near-black reads better on `bg`. */
export const readableOn = (bg: RGB): [number, number, number] => (contrastRatio([255, 255, 255], bg) >= contrastRatio([0, 0, 0], bg) ? [255, 255, 255] : [0, 0, 0])
