import type { Pixels } from '../analysis/pixels'
import { contrast, hex, hsvToRgb, rgbToHsv, type RGB } from './color'
import { mulberry32 } from './prng'

/**
 * CREATIVE-ENGINE-001 — `palette`, `content_hue` and `derive_tokens` from the reference. Colours follow the CONTENT: the accent is
 * the signature hue around the product, warm skin and wood tones count as background and only win when nothing else has colour, the
 * surface is a light tint of the same hue, and every text colour is solved for 4.5:1. The brand's own colours win only with lock_color.
 */
export interface Brand { accent?: string | null; surface?: string | null; lockColor?: boolean; fontPair?: 'luxury' | 'bold' | 'soft' | null }

export interface ContentTokens {
  accent: RGB; surface: RGB; surfaceText: RGB; onAccent: RGB; light: RGB; dark: RGB
  pair: 'luxury' | 'bold' | 'soft'
  colorSource: 'brand' | 'content' | 'content-neutral'
  palette: string[]
}

const fromHex = (h: string): RGB => {
  const s = h.replace('#', '')

  return [parseInt(s.slice(0, 2), 16), parseInt(s.slice(2, 4), 16), parseInt(s.slice(4, 6), 16)]
}

function pixelsRgb(src: Pixels): RGB[] {
  const out: RGB[] = []
  for (let i = 0; i < src.width * src.height; i++) out.push([Number(src.data[i * 4]), Number(src.data[i * 4 + 1]), Number(src.data[i * 4 + 2])])

  return out
}

/** k-means, 12 rounds, seeded so the same picture always gives the same palette; clusters sorted by size. */
export function palette(src: Pixels, k = 5): RGB[] {
  const a = pixelsRgb(src)
  if (a.length === 0) return []
  const rand = mulberry32(1)
  let c: number[][] = Array.from({ length: k }, () => [...a[Math.floor(rand() * a.length)]!])
  let lab = new Int32Array(a.length)
  for (let round = 0; round < 12; round++) {
    lab = new Int32Array(a.length)
    for (let i = 0; i < a.length; i++) {
      let best = 0
      let bd = Infinity
      for (let j = 0; j < k; j++) {
        const d = (a[i]![0] - c[j]![0]!) ** 2 + (a[i]![1] - c[j]![1]!) ** 2 + (a[i]![2] - c[j]![2]!) ** 2
        if (d < bd) { bd = d; best = j }
      }
      lab[i] = best
    }
    c = c.map((old, j) => {
      let n = 0
      const s = [0, 0, 0]
      for (let i = 0; i < a.length; i++) if (lab[i] === j) { n++; s[0]! += a[i]![0]; s[1]! += a[i]![1]; s[2]! += a[i]![2] }

      return n ? s.map((v) => v / n) : old
    })
  }
  const cnt = Array.from({ length: k }, (_, j) => lab.filter((l) => l === j).length)

  return cnt.map((n, j) => [n, j] as const).sort((x, y) => y[0] - x[0]).map(([, j]) => c[j]!.map((v) => Math.trunc(v)) as unknown as RGB)
}

/** Signature hue of the content, weighted towards the focus. Expects a 90×160 copy, as the reference reads it. */
export function contentHue(src: Pixels, focus: { x: number; y: number }): { h: number; s: number; v: number } | null {
  const W = src.width
  const Hh = src.height
  const bins: number[] = []
  const hs: number[] = []
  const ss: number[] = []
  const vs: number[] = []
  const ws: number[] = []
  const warm: boolean[] = []
  for (let y = 0; y < Hh; y++) {
    for (let x = 0; x < W; x++) {
      const i = (y * W + x) * 4
      // PIL's HSV is quantised to bytes; the reference divides those by 255.
      const [h0, s0, v0] = rgbToHsv([Number(src.data[i]), Number(src.data[i + 1]), Number(src.data[i + 2])])
      const h = Math.trunc(h0 * 255) / 255
      const s = Math.trunc(s0 * 255) / 255
      const v = Math.trunc(v0 * 255) / 255
      const fw = Math.exp(-(((x / W - focus.x) ** 2) + ((y / Hh - focus.y) ** 2)) / (2 * 0.16 ** 2))
      const w = fw * Math.min(Math.max(s, 0), 0.6) * Math.min(Math.max((v - 0.1) / 0.25, 0), 1)
      const hd = h * 360
      bins.push(Math.trunc(h * 24) % 24); hs.push(h); ss.push(s); vs.push(v); ws.push(w)
      warm.push(hd > 8 && hd < 48 && s > 0.18)
    }
  }
  const peak = (mask: (i: number) => boolean) => {
    const hist = new Array(24).fill(0)
    bins.forEach((b, i) => { if (mask(i)) hist[b] += ws[i]! })
    const sm = hist.map((v, i) => v + 0.5 * hist[(i + 23) % 24] + 0.5 * hist[(i + 1) % 24])
    let bi = 0
    sm.forEach((v, i) => { if (v > sm[bi]) bi = i })

    return [bi, sm[bi] as number] as const
  }
  const [bw, ww] = peak((i) => warm[i]!)
  const [bo, wo] = peak((i) => !warm[i])
  const bi = wo >= 0.06 * ww && wo > 0 ? bo : bw
  let re = 0
  let im = 0
  let sw = 0
  let sSum = 0
  let vSum = 0
  bins.forEach((b, i) => {
    if (Math.abs(((b - bi + 12) % 24 + 24) % 24 - 12) <= 1 && ws[i]! > 0) {
      const w = ws[i]!
      re += w * Math.cos(2 * Math.PI * hs[i]!); im += w * Math.sin(2 * Math.PI * hs[i]!)
      sw += w; sSum += w * ss[i]!; vSum += w * vs[i]!
    }
  })
  if (sw === 0) return null

  return { h: ((Math.atan2(im, re) / (2 * Math.PI)) % 1 + 1) % 1, s: sSum / sw, v: vSum / sw }
}

export function deriveContentTokens(cols: RGB[], hue: { h: number; s: number; v: number } | null, brand: Brand = {}): ContentTokens {
  let accent: RGB
  let colorSource: ContentTokens['colorSource']
  if (brand.lockColor && brand.accent) {
    accent = fromHex(brand.accent); colorSource = 'brand'
  } else if (hue && hue.s > 0.12) {
    accent = hsvToRgb(hue.h, Math.min(Math.max(hue.s, 0.4), 0.72), Math.min(Math.max(hue.v, 0.5), 0.82)); colorSource = 'content'
  } else {
    accent = hsvToRgb(rgbToHsv(cols[0] ?? [128, 128, 128])[0], 0.22, 0.6); colorSource = 'content-neutral'
  }
  const ha = rgbToHsv(accent)[0]
  const surface = brand.lockColor && brand.surface ? fromHex(brand.surface) : hsvToRgb(ha, 0.07, 0.97)
  const [h, s, v0] = rgbToHsv(accent)
  let v = v0
  let st = hsvToRgb(h, Math.min(Math.max(s, 0.45), 0.75), Math.min(v, 0.45))
  while (contrast(st, surface) < 4.5 && v > 0.05) {
    v -= 0.04
    st = hsvToRgb(h, Math.min(Math.max(s, 0.45), 0.8), v)
  }
  const white: RGB = [255, 255, 255]
  const ink: RGB = [20, 20, 20]
  const onAccent = contrast(white, accent) >= contrast(ink, accent) ? white : ink
  const hsv = cols.map(rgbToHsv)
  const meanV = hsv.reduce((n, c) => n + c[2], 0) / Math.max(1, hsv.length)
  const sat = hsv.reduce((n, c) => n + c[1], 0) / Math.max(1, hsv.length)
  const pair = brand.fontPair ?? (sat < 0.35 && meanV < 0.6 ? 'luxury' : sat > 0.45 ? 'bold' : 'soft')

  return { accent, surface, surfaceText: st, onAccent, light: white, dark: [22, 20, 18], pair, colorSource, palette: cols.map(hex) }
}
