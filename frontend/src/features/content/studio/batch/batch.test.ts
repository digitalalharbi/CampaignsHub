import { describe, expect, it } from 'vitest'
import type { Pixels } from '../analysis/pixels'
import { approxMeasure } from '../motion/text'
import { restyle } from '../tokens/tokens'
import { buildStudioBatch } from './buildBatch'
import { copyIsClean, suggestCopy } from './copySuggest'

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

const PICTURES = {
  dark: image(360, 640, () => [18, 20, 28]),
  light: image(360, 640, () => [238, 236, 230]),
  product: image(360, 640, (x, y) => (x > 60 && x < 200 && y > 160 && y < 300 ? [200, 40, 50] : [110, 112, 118])),
}

describe('the copy suggestions', () => {
  it('give three alternatives that always stay inside the hard limits, in both languages and with any facts', () => {
    const cases = [{}, { name: 'عطر العود الملكي الفاخر الجديد' }, { name: 'Royal Oud Perfume Collection Limited Edition Spring', price: '299 SAR', discountPercent: 30, code: 'OUD30' }, { price: '49 ر.س' }]

    for (const facts of cases) {
      for (const locale of ['ar', 'en'] as const) {
        const options = suggestCopy(facts, locale)

        expect(options).toHaveLength(3)
        for (const c of options) expect(copyIsClean(c), `${JSON.stringify(facts)}/${locale}: ${JSON.stringify(c)}`).toBe(true)
      }
    }
  })

  it('never invents a number: a discount appears only when the product data states one', () => {
    expect(suggestCopy({ name: 'عطر' }, 'ar').some((c) => /٪|%/.test(`${c.hook} ${c.message}`))).toBe(false)
    expect(suggestCopy({ discountPercent: 30 }, 'ar')[0]!.hook).toContain('30')
  })
})

describe('one batch from one picture', () => {
  const copy = suggestCopy({ name: 'عطر فاخر', price: '299 ر.س' }, 'ar')[0]!

  it('is 3 videos and 4 images from seven different families, every one passing the hard rules', () => {
    for (const [name, pixels] of Object.entries(PICTURES)) {
      const batch = buildStudioBatch({ pixels, copy, locale: 'ar', measure: approxMeasure })

      expect(batch.outputs, name).toHaveLength(7)
      expect(batch.outputs.filter((o) => o.as === 'video'), name).toHaveLength(3)
      expect(batch.outputs.filter((o) => o.as === 'image'), name).toHaveLength(4)
      expect(new Set(batch.outputs.map((o) => o.pick.family.key)).size, name).toBe(7)
      for (const o of batch.outputs) expect(o.hard, `${name}/${o.pick.family.key}`).toEqual([])
      expect(batch.lint, name).toEqual([])
    }
  })

  it('shares ONE token set across the batch, and every video has a timeline while no image does', () => {
    const batch = buildStudioBatch({ pixels: PICTURES.product, copy, locale: 'ar', measure: approxMeasure })

    for (const o of batch.outputs) {
      expect(o.scene.layers.every((l) => l.font === 'display' || l.font === 'text')).toBe(true)
      expect(o.timeline === undefined, o.pick.family.key).toBe(o.as === 'image')
      expect(o.scene.durationMs).toBe(o.as === 'video' ? 12000 : 0)
    }
  })

  it('«new batch» changes the combination and re-uses the analysis instead of reading the picture again', () => {
    const first = buildStudioBatch({ pixels: PICTURES.product, copy, locale: 'ar', measure: approxMeasure })
    const second = buildStudioBatch({ pixels: PICTURES.product, copy, locale: 'ar', measure: approxMeasure, analysis: first.analysis, tokens: first.tokens, round: 1 })

    expect(second.analysis).toBe(first.analysis)
    expect(second.outputs.map((o) => `${o.as}:${o.pick.family.key}`).join()).not.toBe(first.outputs.map((o) => `${o.as}:${o.pick.family.key}`).join())
  })

  it('«swap style» changes the font pair and accent of the WHOLE batch and nothing else', () => {
    const first = buildStudioBatch({ pixels: PICTURES.product, copy, locale: 'ar', measure: approxMeasure })
    const tokens = restyle(first.tokens, { fontPairKey: 'cairo-tajawal', accent: '#e0a800' })
    const swapped = buildStudioBatch({ pixels: PICTURES.product, copy, locale: 'ar', measure: approxMeasure, analysis: first.analysis, tokens })

    expect(swapped.tokens.fontPair.key).toBe('cairo-tajawal')
    expect(swapped.lint).toEqual([])
    expect(swapped.outputs.map((o) => o.pick.family.key)).toEqual(first.outputs.map((o) => o.pick.family.key))
    expect(swapped.outputs.map((o) => o.layers)).toEqual(first.outputs.map((o) => o.layers))
  })

  it('a hashtag or an emoji in the copy blocks every output, with the field named', () => {
    const batch = buildStudioBatch({ pixels: PICTURES.dark, copy: { ...copy, message: 'عطر #جديد 🔥' }, locale: 'ar', measure: approxMeasure })

    for (const o of batch.outputs) {
      expect(o.hard.map((h) => h.code)).toEqual(expect.arrayContaining(['hashtag', 'emoji']))
    }
  })

  it('works in English too', () => {
    const en = suggestCopy({ name: 'Royal Oud', price: '299 SAR' }, 'en')[0]!
    const batch = buildStudioBatch({ pixels: PICTURES.light, copy: en, locale: 'en', measure: approxMeasure })

    for (const o of batch.outputs) expect(o.hard).toEqual([])
    expect(batch.outputs[0]!.scene.direction).toBe('ltr')
  })
})
