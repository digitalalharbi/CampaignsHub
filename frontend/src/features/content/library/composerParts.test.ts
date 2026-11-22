import { describe, expect, it } from 'vitest'
import { countWords, hasEmoji, hasHashtag, LIMITS } from '../studio/direction/copyRules'
import { drawStyledLayer, LOOK_BY_FAMILY } from '../studio/motion/look'
import type { Scene, SceneLayer } from '../studio/motion/scene'
import { crc32, zipFiles } from '../studio/export/zip'
import { scoreText, strategies } from './textStrategies'

describe('CONTENT-LIBRARY-SNAP-001 — text strategies and score', () => {
  it('builds every line from what was typed, within the limits, with no hashtag or emoji', () => {
    const all = strategies({ product: 'سبحة كهرمان', price: '199 ريال', description: 'صناعة يدوية' }, 'ar')
    expect(all.map((s) => s.key)).toEqual(['price', 'quality', 'now'])
    for (const s of all) {
      for (const h of s.headlines) expect(countWords(h)).toBeLessThanOrEqual(LIMITS.hook)
      for (const p of s.primaries) expect(countWords(p)).toBeLessThanOrEqual(LIMITS.message)
      for (const line of [...s.headlines, ...s.primaries]) expect(hasHashtag(line) || hasEmoji(line)).toBe(false)
    }
    expect(all[0]!.headlines.join(' ')).toContain('199 ريال')
  })

  it('offers no price strategy when no price was typed', () => {
    expect(strategies({ product: 'خاتم', price: '', description: '' }, 'ar').some((s) => s.key === 'price')).toBe(false)
  })

  it('scores from the rules and names what is missing', () => {
    const weak = scoreText({ primaries: [''], headlines: ['#عرض'], description: '', cta: '' }, 'en')
    expect(weak.score).toBeLessThan(50)
    expect(weak.tips).toContain('Choose a call to action')
    const full = scoreText({ primaries: ['Rings at 199', 'Order today'], headlines: ['Rings at 199', 'Handmade'], description: 'Silver', cta: 'Shop now' }, 'en')
    expect(full.score).toBe(100)
    expect(full.tips).toEqual([])
  })
})

describe('CONTENT-LIBRARY-SNAP-001 — zip', () => {
  it('computes the standard CRC-32', () => {
    expect(crc32(new TextEncoder().encode('123456789'))).toBe(0xcbf43926)
  })

  it('writes a stored archive with one entry per file and the end record', async () => {
    const zip = await zipFiles([{ name: 'a.png', blob: new Blob(['abc']) }, { name: 'ب.mp4', blob: new Blob(['de']) }])
    const bytes = new Uint8Array(await zip.arrayBuffer())
    const view = new DataView(bytes.buffer)
    expect(view.getUint32(0, true)).toBe(0x04034b50)
    const end = bytes.length - 22
    expect(view.getUint32(end, true)).toBe(0x06054b50)
    expect(view.getUint16(end + 10, true)).toBe(2)
  })
})

describe('CONTENT-LIBRARY-SNAP-001 — looks', () => {
  const layer = (over: Partial<SceneLayer>): SceneLayer => ({
    id: 'cta', role: 'cta', kind: 'cta', rect: { x: 100, y: 1500, w: 600, h: 120 }, lines: ['اطلب الآن'], fontPx: 40, font: 'display',
    color: '#fff', fill: '#000', enterAt: 0, enterMs: 300, recipe: 'fade', ...over,
  } as SceneLayer)
  const scene = { format: '9x16', width: 1080, height: 1920, durationMs: 0, direction: 'rtl', layers: [], overflow: [] } as unknown as Scene

  const recorder = () => {
    const calls: string[] = []
    const ctx = {
      save() {}, restore() {}, translate() {}, scale() {}, beginPath() {}, rect() {}, clip() {},
      fillRect: (...a: number[]) => calls.push(`fillRect:${a.join(',')}`),
      fillText: (s: string) => calls.push(`text:${s}`),
      roundRect: (x: number, y: number, w: number, h: number) => calls.push(`round:${Math.round(x)},${Math.round(y)},${Math.round(w)},${Math.round(h)}`),
      fill() {}, rotate() {},
      measureText: (s: string) => ({ width: s.length * 20 }),
      globalAlpha: 1, fillStyle: '', font: '', textAlign: 'center', textBaseline: 'middle', direction: 'rtl', shadowBlur: 0, shadowColor: '', shadowOffsetY: 0,
    }

    return { ctx, calls }
  }

  it('every system family has a look', () => {
    expect(Object.keys(LOOK_BY_FAMILY).sort()).toEqual(['bold-hook', 'detail-cuts', 'editorial-frame', 'minimal', 'offer-card', 'split', 'stacked-reveal'])
  })

  it('draws the CTA as a pill inside its rect, with an arrow in the reading direction', () => {
    const { ctx, calls } = recorder()
    expect(drawStyledLayer(ctx as never, layer({}), scene, { look: 'glass' }, { display: 'a', text: 'b' })).toBe(true)
    const pill = calls.find((c) => c.startsWith('round:'))!.split(':')[1]!.split(',').map(Number)
    expect(pill[2]).toBeLessThanOrEqual(600)
    expect(pill[3]).toBeLessThanOrEqual(120)
    expect(calls).toContain('text:اطلب الآن  ←')
  })

  it('a sticker CTA carries the link mark', () => {
    const { ctx, calls } = recorder()
    drawStyledLayer(ctx as never, layer({}), scene, { look: 'glass', ctaSticker: true }, { display: 'a', text: 'b' })
    expect(calls).toContain('text:↗ اطلب الآن')
  })

  it('a marker hook puts a tight highlight behind each line', () => {
    const { ctx, calls } = recorder()
    drawStyledLayer(ctx as never, layer({ role: 'hook', lines: ['سطر أول', 'ثاني'], fill: null }), scene, { look: 'marker' }, { display: 'a', text: 'b' })
    expect(calls.filter((c) => c.startsWith('round:'))).toHaveLength(2)
  })

  it('brings the size down until the widest line fits its block — no word is cut at the edge', () => {
    const { ctx } = recorder()
    drawStyledLayer(ctx as never, layer({ role: 'hook', lines: ['المنتج الثاني بنصف السعر الآن'], fill: null, fontPx: 80, rect: { x: 0, y: 300, w: 400, h: 200 } }), scene, { look: 'bold' }, { display: 'a', text: 'b' })
    expect(Number(/ (\d+)px/.exec(ctx.font)![1])).toBeLessThan(80)
  })

  it('a stub that cannot measure keeps the plain drawing', () => {
    const { ctx } = recorder()
    const plain = { ...ctx, measureText: undefined }
    expect(drawStyledLayer(plain as never, layer({}), scene, { look: 'glass' }, { display: 'a', text: 'b' })).toBe(false)
  })
})
