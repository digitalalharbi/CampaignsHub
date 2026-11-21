import { describe, expect, it } from 'vitest'
import { analyzeImage } from '../analysis/analyzeImage'
import type { Pixels } from '../analysis/pixels'
import { hardChecks } from '../direction/hardChecks'
import { FORMATS, marginViolations, type FormatKey, type Layer } from '../direction/safeZone'
import { buildTimeline } from '../direction/timeline'
import { ANCHORS, familySchema, SYSTEM_FAMILIES } from './index'
import { placeFamily } from './placeFamily'
import { pickBatch } from './selectBatch'

function image(width: number, height: number, paint: (x: number, y: number) => [number, number, number]): Pixels {
  const data = new Uint8ClampedArray(width * height * 4)

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const [r, g, b] = paint(x, y)
      const i = (y * width + x) * 4
      data[i] = r; data[i + 1] = g; data[i + 2] = b; data[i + 3] = 255
    }
  }

  return { width, height, data }
}

const PICTURES: Record<string, Pixels> = {
  dark: image(360, 640, () => [18, 20, 28]),
  light: image(360, 640, () => [238, 236, 230]),
  productOnGrey: image(360, 640, (x, y) => (x > 40 && x < 160 && y > 130 && y < 260 ? [220, 30, 30] : [120, 120, 120])),
  busy: image(360, 640, (x, y) => (((x >> 2) + (y >> 2)) % 2 === 0 ? [255, 255, 255] : [0, 0, 0])),
}
const COPY = { hook: 'خصم ٣٠٪ اليوم', message: 'عطر فاخر يدوم أطول', cta: 'اطلب الآن' }

describe('the seven system families', () => {
  it('are seven, valid, and each carries a hook and a call to action', () => {
    expect(SYSTEM_FAMILIES).toHaveLength(7)
    for (const f of SYSTEM_FAMILIES) {
      expect(familySchema.safeParse(f).success, f.key).toBe(true)
      expect(f.blocks.some((b) => b.role === 'cta'), f.key).toBe(true)
      expect(f.blocks.some((b) => b.role === 'hook'), f.key).toBe(true)
      expect(f.label.ar).not.toBe('')
    }
  })

  it('each takes its own anchor, so no two sit in the same place by design', () => {
    expect(new Set(SYSTEM_FAMILIES.map((f) => f.anchor)).size).toBe(7)
    expect([...SYSTEM_FAMILIES.map((f) => f.anchor)].sort()).toEqual([...ANCHORS].sort())
  })

  it('rejects a family with no call to action', () => {
    const bad = { ...SYSTEM_FAMILIES[0]!, blocks: SYSTEM_FAMILIES[0]!.blocks.filter((b) => b.role !== 'cta') }

    expect(familySchema.safeParse(bad).success).toBe(false)
  })
})

describe('placing a family', () => {
  const formats = Object.keys(FORMATS) as FormatKey[]

  it('puts every layer inside the live area for every family, picture, format and language', () => {
    for (const [name, pixels] of Object.entries(PICTURES)) {
      const analysis = analyzeImage(pixels)

      for (const family of SYSTEM_FAMILIES) {
        for (const format of formats) {
          for (const locale of ['ar', 'en'] as const) {
            const layers = placeFamily(family, analysis, format, locale)

            expect(marginViolations(layers, format), `${name}/${family.key}/${format}/${locale}`).toEqual([])
          }
        }
      }
    }
  })

  it('passes the studio hard checks for a clean video, all seven families', () => {
    const analysis = analyzeImage(PICTURES.productOnGrey!)

    for (const family of SYSTEM_FAMILIES) {
      const layers = placeFamily(family, analysis, '9x16')

      expect(hardChecks({ format: '9x16', layers, copy: COPY, timeline: buildTimeline() }), family.key).toEqual([])
    }
  })

  const overlap = (a: Layer, b: Layer) => a.rect.x < b.rect.x + b.rect.w && a.rect.x + a.rect.w > b.rect.x && a.rect.y < b.rect.y + b.rect.h && a.rect.y + a.rect.h > b.rect.y

  it('never overlaps two text blocks or a text block and the call to action', () => {
    for (const [name, pixels] of Object.entries(PICTURES)) {
      const analysis = analyzeImage(pixels)

      for (const family of SYSTEM_FAMILIES) {
        const layers = placeFamily(family, analysis, '9x16').filter((l) => l.kind !== 'bar')

        for (let i = 0; i < layers.length; i++) {
          for (let j = i + 1; j < layers.length; j++) {
            expect(overlap(layers[i]!, layers[j]!), `${name}/${family.key}: ${layers[i]!.id} × ${layers[j]!.id}`).toBe(false)
          }
        }
      }
    }
  })

  it('keeps the hook away from the product when there is room', () => {
    const analysis = analyzeImage(PICTURES.productOnGrey!)
    const layers = placeFamily(SYSTEM_FAMILIES.find((f) => f.key === 'bold-hook')!, analysis, '9x16')
    const hook = layers.find((l) => l.id === 'hook')!.rect
    const p = analysis.product
    const canvas = FORMATS['9x16']
    const box = { x: p.x * canvas.width, y: p.y * canvas.height, w: p.w * canvas.width, h: p.h * canvas.height }

    expect(hook.x < box.x + box.w && hook.x + hook.w > box.x && hook.y < box.y + box.h && hook.y + hook.h > box.y).toBe(false)
  })

  it('mirrors for English: the start-aligned logo moves from the right edge to the left', () => {
    const analysis = analyzeImage(PICTURES.dark!)
    const family = SYSTEM_FAMILIES.find((f) => f.key === 'stacked-reveal')!
    const ar = placeFamily(family, analysis, '9x16', 'ar').find((l) => l.id === 'logo')!.rect
    const en = placeFamily(family, analysis, '9x16', 'en').find((l) => l.id === 'logo')!.rect

    expect(ar.x).toBeGreaterThan(en.x)
  })
})

describe('choosing a batch', () => {
  it('3 videos + 4 images over the seven families uses each family and each anchor exactly once', () => {
    const batch = pickBatch()

    expect(batch).toHaveLength(7)
    expect(batch.filter((b) => b.as === 'video')).toHaveLength(3)
    expect(batch.filter((b) => b.as === 'image')).toHaveLength(4)
    expect(new Set(batch.map((b) => b.family.key)).size).toBe(7)
    expect(new Set(batch.map((b) => b.family.anchor)).size).toBe(7)
    expect(batch.some((b) => b.repeated)).toBe(false)
  })

  it('respects what each family suits: detail cuts only as a video, the editorial frame only as an image', () => {
    for (let round = 0; round < 7; round++) {
      for (const b of pickBatch({ round })) {
        expect(b.family.kind === 'both' || b.family.kind === b.as, `${b.family.key} as ${b.as}`).toBe(true)
      }
    }
  })

  it('«new batch» changes the combination, and a liked family is ranked first', () => {
    const a = pickBatch({ round: 0 }).map((b) => `${b.as}:${b.family.key}`).join()
    const b = pickBatch({ round: 1 }).map((x) => `${x.as}:${x.family.key}`).join()

    expect(a).not.toBe(b)
    expect(pickBatch({ videos: 1, images: 0, scores: { 'offer-card': 5 } })[0]!.family.key).toBe('offer-card')
  })

  it('flags a repeat instead of returning fewer outputs when the pool is too small', () => {
    const batch = pickBatch({ videos: 6, images: 6 })

    expect(batch).toHaveLength(12)
    expect(batch.filter((b) => b.repeated).length).toBeGreaterThan(0)
  })
})

function lcg(seed: number) {
  let s = seed >>> 0

  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0

    return s / 2 ** 32
  }
}

describe('property: any picture, any family, any format — nothing leaves the live area or overlaps', () => {
  it('holds for 60 seeded random pictures', () => {
    const rnd = lcg(20261121)
    const overlap = (a: Layer, b: Layer) => a.rect.x < b.rect.x + b.rect.w && a.rect.x + a.rect.w > b.rect.x && a.rect.y < b.rect.y + b.rect.h && a.rect.y + a.rect.h > b.rect.y

    for (let i = 0; i < 60; i++) {
      const w = 60 + Math.floor(rnd() * 300)
      const h = 60 + Math.floor(rnd() * 500)
      const base: [number, number, number] = [Math.floor(rnd() * 256), Math.floor(rnd() * 256), Math.floor(rnd() * 256)]
      const noise = rnd() < 0.4 ? 140 : 0
      const analysis = analyzeImage(image(w, h, () => [0, 1, 2].map((k) => Math.min(255, Math.max(0, base[k]! + (rnd() - 0.5) * noise))) as [number, number, number]))

      for (const family of SYSTEM_FAMILIES) {
        for (const format of ['9x16', '4x5', '1x1'] as const) {
          const layers = placeFamily(family, analysis, format, rnd() < 0.5 ? 'ar' : 'en')
          const flat = layers.filter((l) => l.kind !== 'bar')

          expect(marginViolations(layers, format), `${i}/${family.key}/${format}`).toEqual([])
          for (let a = 0; a < flat.length; a++) for (let b = a + 1; b < flat.length; b++) expect(overlap(flat[a]!, flat[b]!), `${i}/${family.key}/${format}: ${flat[a]!.id}×${flat[b]!.id}`).toBe(false)
        }
      }
    }
  })
})
