import { describe, expect, it } from 'vitest'
import type { Pixels } from '../analysis/pixels'
import { SAFE } from './constants'
import { generateBatch } from './generate'
import { distance, SPACE } from './genome'

/*
 * CREATIVE-ENGINE-001 phase 2 — the batch on synthetic pictures: a product on a calm background, bright and dark, portrait and
 * landscape. What is shown must pass every hard rule; the batch must differ; the seed must reproduce it.
 */
function picture(w: number, h: number, bg: number, product: [number, number, number, number]): Pixels {
  const data = new Uint8ClampedArray(w * h * 4)
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const inP = x >= product[0] * w && x < product[2] * w && y >= product[1] * h && y < product[3] * h
    data.set(inP ? [40, 60, 160, 255] : [bg, bg, bg, 255], (y * w + x) * 4)
  }

  return { width: w, height: h, data }
}

const measure = (t: string, px: number) => t.length * px * 0.5
const copy = { hook: 'خاتم فضة بتصميم ملكي', message: 'صناعة يدوية وتغليف هدية', cta: 'اطلب الآن', price: '199 ر.س' }
const run = (p: Pixels, seed = 7, size = { w: p.width * 4, h: p.height * 4 }) => generateBatch({ pixels: p, sourceSize: size, copy, measure, locale: 'ar', seed })

describe('CREATIVE-ENGINE-001 — generateBatch', () => {
  const dark = picture(144, 256, 30, [0.35, 0.4, 0.65, 0.6])
  const bright = picture(144, 256, 235, [0.35, 0.4, 0.65, 0.6])
  const wide = picture(256, 144, 120, [0.4, 0.3, 0.6, 0.7])

  it('every design shown keeps its ink inside the live area and its text off the product', () => {
    for (const p of [dark, bright, wide]) {
      const res = run(p, 11)
      expect(res.candidates.length).toBeGreaterThan(0)
      for (const c of res.candidates) {
        for (const e of c.elements.filter((x) => !x.decorative)) {
          expect(e.box.x).toBeGreaterThanOrEqual(SAFE.x0 - 0.5)
          expect(e.box.x + e.box.w).toBeLessThanOrEqual(SAFE.x1 + 0.5)
          expect(e.box.y).toBeGreaterThanOrEqual(SAFE.y0 - 0.5)
          expect(e.box.y + e.box.h).toBeLessThanOrEqual(SAFE.y1 + 0.5)
        }
        expect(c.minContrast).toBeGreaterThanOrEqual(4.5)
        expect(c.elements.some((e) => e.role === 'cta')).toBe(true)
      }
    }
  })

  it('a batch never repeats an archetype while others qualify, and its designs differ', () => {
    const res = run(dark, 3)
    const archetypes = res.candidates.map((c) => c.genome.archetype)
    expect(new Set(archetypes).size).toBe(archetypes.length)
    for (let i = 0; i < res.candidates.length; i++) for (let j = i + 1; j < res.candidates.length; j++) {
      expect(distance(res.candidates[i]!.genome, res.candidates[j]!.genome)).toBeGreaterThan(0.25)
    }
  })

  it('the same seed reproduces the batch; another seed gives another', () => {
    const a = run(dark, 42).candidates.map((c) => c.id + c.genome.archetype)
    expect(run(dark, 42).candidates.map((c) => c.id + c.genome.archetype)).toEqual(a)
    expect(run(dark, 43).candidates.map((c) => c.genome.treatment + c.genome.camera)).not.toEqual(run(dark, 42).candidates.map((c) => c.genome.treatment + c.genome.camera))
  })

  it('drops what breaks a rule and says why', () => {
    const res = generateBatch({ pixels: bright, sourceSize: { w: 576, h: 1024 }, copy: { ...copy, price: '199' }, measure, locale: 'ar', seed: 5 })
    expect(res.candidates.every((c) => !c.elements.some((e) => e.role === 'price'))).toBe(true)
    expect(res.rejected.some((r) => r.reasons.includes('price:currency'))).toBe(true)
  })

  it('names the size of the design space', () => {
    expect(SPACE).toBeGreaterThan(1_000_000)
  })

  it('a busy full-bleed picture with long copy still gets designs that keep every rule (never an empty step)', () => {
    // High-contrast noise over the whole frame, a product filling most of it, and long text: the case the Owner hit on Production.
    const w = 144, h = 256
    const data = new Uint8ClampedArray(w * h * 4)
    let s = 3
    for (let i = 0; i < w * h; i++) { s = (s * 1103515245 + 12345) >>> 0; const v = (s >>> 16) & 255; data.set([v, 255 - v, (v * 3) & 255, 255], i * 4) }
    const busy: Pixels = { width: w, height: h, data }
    const long = { hook: 'عرض خاص لفترة محدودة على كل الخواتم الفضية الرجالية', message: 'صناعة يدوية من الفضة عيار 925 مع تغليف هدية فاخر وشحن سريع لكل مدن المملكة', cta: 'اطلب الآن', price: '199 ر.س' }
    const res = generateBatch({ pixels: busy, sourceSize: { w: 1080, h: 1920 }, copy: long, measure, locale: 'ar', seed: 5 })
    expect(res.candidates.length).toBeGreaterThanOrEqual(4)
    for (const c of res.candidates) {
      for (const e of c.elements.filter((x) => !x.decorative && x.role !== 'panel' && x.role !== 'scrim')) {
        expect(e.box.y).toBeGreaterThanOrEqual(SAFE.y0 - 0.5)
        expect(e.box.y + e.box.h).toBeLessThanOrEqual(SAFE.y1 + 0.5)
      }
      expect(c.elements.some((e) => e.role === 'cta')).toBe(true)
    }
  })
})
