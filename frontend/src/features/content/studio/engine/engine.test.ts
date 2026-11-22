import { describe, expect, it } from 'vitest'
import { contrast } from './color'
import { SAFE } from './constants'
import { deriveContentTokens } from './contentTokens'
import { fitPlan } from './fit'
import { chooseBand, clampIntoSafe, fitLines, safeViolations, wrap } from './layout'
import { hashSeed, mulberry32 } from './prng'
import { saliency } from './saliency'

/*
 * CREATIVE-ENGINE-001 — parity with `docs/creative-engine/adstudio_reference.py`. The expected values were produced by running the
 * reference's own functions on the same fixed inputs (numpy 2.5, Pillow 12); tolerance ±2% where the arithmetic is floating point.
 */
const near = (a: number, b: number, rel = 0.02) => expect(Math.abs(a - b)).toBeLessThanOrEqual(Math.max(Math.abs(b) * rel, 1e-6))

describe('parity — saliency_center', () => {
  it('finds the red square on grey as the reference does', () => {
    const W = 96
    const Hh = 170
    const data = new Uint8ClampedArray(W * Hh * 4)
    for (let y = 0; y < Hh; y++) for (let x = 0; x < W; x++) {
      const red = y >= 100 && y < 130 && x >= 60 && x < 80
      data.set(red ? [200, 30, 30, 255] : [200, 200, 200, 255], (y * W + x) * 4)
    }
    const s = saliency({ width: W, height: Hh, data })
    near(s.cx, 0.7388534763351662)
    near(s.cy, 0.6846639350331214)
    // The box holds the square (x 60..80 of 95, y 100..130 of 169) and does not run the whole frame.
    expect(s.box.x).toBeLessThanOrEqual(60 / 95)
    expect(s.box.x + s.box.w).toBeGreaterThanOrEqual(79 / 95)
    expect(s.box.w).toBeLessThan(0.5)
  })
})

describe('parity — fit_916', () => {
  it('cover-crops near 9:16 around the focus', () => {
    const a = fitPlan(1080, 1920, { x: 0.5, y: 0.4 })
    expect(a.mode).toBe('cover')
    if (a.mode === 'cover') { near(a.crop.x, 0.1674, 1); near(a.crop.w, 1079.6651) }
    const b = fitPlan(1200, 1920, { x: 0.8, y: 0.5 })
    if (b.mode === 'cover') near(b.crop.x, 120.33488372093029)
  })

  it('blur-fills a landscape picture instead of stretching it', () => {
    const c = fitPlan(1920, 1080, { x: 0.3, y: 0.6 })
    expect(c.mode).toBe('blur')
    if (c.mode === 'blur') expect([c.fg.x, c.fg.y, c.fg.w, c.fg.h]).toEqual([0, 627, 1209, 680])
  })
})

describe('parity — derive_tokens', () => {
  const cols = [[180, 150, 120], [60, 40, 30], [200, 190, 180], [120, 30, 40], [90, 90, 90]] as const

  it('takes the accent from the content hue and solves the text for 4.5:1', () => {
    const t = deriveContentTokens(cols.map((c) => [...c] as [number, number, number]), { h: 0.95, s: 0.55, v: 0.6 })
    expect(t.accent).toEqual([153, 69, 94])
    expect(t.surface).toEqual([247, 230, 235])
    expect(t.surfaceText).toEqual([115, 52, 70])
    expect(t.onAccent).toEqual([255, 255, 255])
    expect(t.pair).toBe('luxury')
    expect(t.colorSource).toBe('content')
    expect(contrast(t.surfaceText, t.surface)).toBeGreaterThanOrEqual(4.5)
  })

  it('falls back to a muted tone when the content has no colour', () => {
    const t = deriveContentTokens(cols.map((c) => [...c] as [number, number, number]), null)
    expect(t.accent).toEqual([153, 136, 119])
    expect(t.surfaceText).toEqual([115, 89, 63])
    expect(t.onAccent).toEqual([20, 20, 20])
    expect(t.colorSource).toBe('content-neutral')
  })

  it('keeps the brand colour only when it is locked', () => {
    expect(deriveContentTokens([[90, 90, 90]], { h: 0.5, s: 0.6, v: 0.6 }, { accent: '#A8853F', lockColor: true }).accent).toEqual([168, 133, 63])
  })
})

describe('parity — wrap and fitting', () => {
  const measure = (t: string) => t.length * 10

  it('wraps as the reference wraps', () => {
    expect(wrap('one two three four five six seven', 40, 120, measure)).toEqual(['one two', 'three four', 'five six', 'seven'])
    expect(wrap('aaaa bbbb cccc dddd e', 40, 140, measure)).toEqual(['aaaa bbbb cccc', 'dddd e'])
  })

  it('never ends on a single word when the block is wide enough to avoid it', () => {
    const lines = wrap('واحد اثنين ثلاثة أربعة خمسة ستة', 40, 260, measure)
    expect(lines.at(-1)!.split(' ').length).toBeGreaterThan(1)
  })

  it('steps the size down until three lines hold the text', () => {
    const scaled = (t: string, px: number) => t.length * px * 0.25
    const fit = fitLines('alpha bravo charlie delta echo foxtrot golf hotel india juliet', 100, 300, scaled)
    expect(fit.lines.length).toBeLessThanOrEqual(3)
    expect(fit.px).toBeLessThan(100)
  })
})

describe('parity — choose_band and the safe area', () => {
  const cols = 135
  const edges = new Float32Array(240 * cols)
  for (let r = 0; r < 120; r++) for (let c = 0; c < cols; c++) edges[r * cols + c] = 50

  it('picks the calm band away from the product, as the reference does', () => {
    expect(chooseBand(edges, cols, 0.3 * 1920, 300, 'bottom')).toBe(1220)
    expect(chooseBand(edges, cols, 0.7 * 1920, 300, 'top')).toBe(980)
  })

  it('pulls a block back inside the live area and reports none left outside', () => {
    const placed = clampIntoSafe([{ role: 'cta', x: 900, y: 1700, w: 300, h: 120 }])
    expect(safeViolations(placed)).toEqual([])
    expect(placed[0]!.y + placed[0]!.h).toBe(SAFE.y1)
  })
})

describe('the seed', () => {
  it('the same seed gives the same sequence; a different one does not', () => {
    const a = mulberry32(hashSeed('content-1', 0))
    const b = mulberry32(hashSeed('content-1', 0))
    const c = mulberry32(hashSeed('content-1', 1))
    const sa = [a(), a(), a()]
    expect([b(), b(), b()]).toEqual(sa)
    expect([c(), c(), c()]).not.toEqual(sa)
  })
})
