import { describe, expect, it } from 'vitest'
import { hardChecks } from './hardChecks'
import { countWords, forbiddenWordsIn, hasEmoji, hasHashtag, type StudioCopy } from './copyRules'
import { FORMATS, insideLiveArea, liveArea, marginViolations, protectedBands, type FormatKey, type Layer } from './safeZone'
import { buildTimeline, MAX_DURATION_MS, timelineProblems } from './timeline'

const COPY: StudioCopy = { hook: 'خصم ٣٠٪ اليوم فقط', message: 'عطر فاخر بتركيز عالٍ يدوم أطول', cta: 'اطلب الآن' }

describe('the safe margins (CONTENT-STUDIO-001)', () => {
  it('9x16 keeps 10% on top, 20% below and 6% on each side — the numbers the agency approved', () => {
    expect(liveArea('9x16')).toEqual({ x: 65, y: 192, w: 950, h: 1344 })
    // The live area is 70% of the height, from y=192 to y=1536.
    expect(liveArea('9x16').y + liveArea('9x16').h).toBe(1536)
    expect(liveArea('9x16').h / FORMATS['9x16'].height).toBeCloseTo(0.7, 5)
  })

  it('the optional ratios keep an inner 6% on every side', () => {
    expect(liveArea('4x5')).toEqual({ x: 65, y: 65, w: 950, h: 1220 })
    expect(liveArea('1x1')).toEqual({ x: 65, y: 65, w: 950, h: 950 })
  })

  it('the protected bands plus the live area cover the canvas exactly', () => {
    for (const f of Object.keys(FORMATS) as FormatKey[]) {
      const { width, height } = FORMATS[f]
      const live = liveArea(f)
      const area = protectedBands(f).reduce((s, r) => s + r.w * r.h, 0) + live.w * live.h

      expect(area, f).toBe(width * height)
    }
  })

  it('a layer touching the top 10% or the bottom 20% fails, and names the edge', () => {
    const layers: Layer[] = [
      { id: 'logo', kind: 'logo', rect: { x: 100, y: 150, w: 200, h: 80 } },
      { id: 'cta', kind: 'cta', rect: { x: 100, y: 1500, w: 400, h: 120 } },
      { id: 'hook', kind: 'text', rect: { x: 100, y: 400, w: 800, h: 200 } },
    ]

    const v = marginViolations(layers, '9x16')

    expect(v.map((x) => [x.layerId, ...x.edges])).toEqual([['logo', 'top'], ['cta', 'bottom']])
  })

  it('a background may cover the whole canvas; a text layer may not touch a side band', () => {
    expect(marginViolations([{ id: 'bg', kind: 'background', rect: { x: 0, y: 0, w: 1080, h: 1920 } }], '9x16')).toEqual([])
    expect(insideLiveArea({ x: 60, y: 400, w: 300, h: 100 }, '9x16')).toBe(false)
    expect(insideLiveArea({ x: 65, y: 192, w: 950, h: 1344 }, '9x16')).toBe(true)
  })
})

describe('the copy rules', () => {
  it('counts Arabic and Latin words alike and ignores bare punctuation', () => {
    expect(countWords('اطلب الآن')).toBe(2)
    expect(countWords('Order now — today')).toBe(3)
    expect(countWords('   ')).toBe(0)
  })

  it('finds a hashtag and an emoji, and nothing in plain Arabic text', () => {
    expect(hasHashtag('عرض #رمضان')).toBe(true)
    expect(hasHashtag('رقم 5# ليس وسماً')).toBe(false)
    expect(hasEmoji('اطلب الآن 🔥')).toBe(true)
    expect(hasEmoji('خصم ٣٠٪ اليوم')).toBe(false)
  })

  it('matches forbidden words whole, so a forbidden word does not flag a longer one', () => {
    expect(forbiddenWordsIn('أرخص سعر', ['سعر'])).toEqual(['سعر'])
    expect(forbiddenWordsIn('أسعار مناسبة', ['سعر'])).toEqual([])
  })
})

describe('the video time structure', () => {
  it('the default is 12s: a 3s hook, a message, and a CTA held for its last 2.5s', () => {
    const t = buildTimeline()

    expect(t).toEqual({ duration: 12000, hook: { start: 0, end: 3000 }, message: { start: 3000, end: 9500 }, cta: { start: 9500, end: 12000 } })
    expect(timelineProblems(t)).toEqual([])
  })

  it('never exceeds 15 seconds, however long it is asked for', () => {
    expect(buildTimeline(40_000).duration).toBe(MAX_DURATION_MS)
    expect(timelineProblems(buildTimeline(40_000))).toEqual([])
  })

  it('reports a timeline that breaks the structure', () => {
    const t = buildTimeline()

    expect(timelineProblems({ ...t, duration: 16000 })).toContain('too_long')
    expect(timelineProblems({ ...t, cta: { start: 11000, end: 12000 } })).toContain('cta_too_short')
    expect(timelineProblems({ ...t, hook: { start: 0, end: 4000 } })).toContain('hook_not_first_3s')
  })
})

describe('the hard checks', () => {
  const layers: Layer[] = [{ id: 'hook', kind: 'text', rect: { x: 100, y: 400, w: 800, h: 200 } }]
  const ok = { format: '9x16' as const, layers, copy: COPY, timeline: buildTimeline() }

  it('passes a clean video', () => {
    expect(hardChecks(ok)).toEqual([])
  })

  it('fails on each rule it is meant to catch', () => {
    const codes = (over: Partial<typeof ok> & { forbiddenWords?: string[] }) => hardChecks({ ...ok, ...over }).map((f) => f.code)

    expect(codes({ copy: { ...COPY, cta: '' } })).toContain('cta_missing')
    expect(codes({ copy: { ...COPY, hook: 'ثلاث كلمات اثنتان واحدة اخرى زيادة هنا ايضا' } })).toContain('hook_too_long')
    expect(codes({ copy: { ...COPY, cta: 'اطلب الآن من هنا فورا' } })).toContain('cta_too_long')
    expect(codes({ copy: { ...COPY, message: 'عطر #جديد' } })).toContain('hashtag')
    expect(codes({ copy: { ...COPY, support: '🔥' } })).toContain('emoji')
    expect(codes({ copy: { ...COPY, support: 'أرخص سعر' }, forbiddenWords: ['سعر'] })).toContain('forbidden_word')
    expect(codes({ timeline: { ...buildTimeline(), duration: 16000 } })).toContain('video_too_long')
    expect(codes({ layers: [{ id: 'cta', kind: 'cta', rect: { x: 100, y: 1500, w: 400, h: 100 } }] })).toContain('layer_in_margin')
  })

  it('an image has no timeline and is still checked for margins and copy', () => {
    const { timeline: _t, ...image } = ok

    expect(hardChecks(image)).toEqual([])
    expect(hardChecks({ ...image, copy: { ...COPY, cta: ' ' } }).map((f) => f.code)).toEqual(['cta_missing'])
  })
})

/** A deterministic generator, so a failing case is the same case on every machine. */
function lcg(seed: number) {
  let s = seed >>> 0

  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0

    return s / 2 ** 32
  }
}

describe('property: a layer placed inside the live area never fails the margin check', () => {
  it('holds for 2,000 random layers across every format', () => {
    const rnd = lcg(20261121)

    for (let i = 0; i < 2000; i++) {
      const format = (Object.keys(FORMATS) as FormatKey[])[Math.floor(rnd() * 4)]!
      const live = liveArea(format)
      const w = 1 + Math.floor(rnd() * live.w)
      const h = 1 + Math.floor(rnd() * live.h)
      const x = live.x + Math.floor(rnd() * (live.w - w + 1))
      const y = live.y + Math.floor(rnd() * (live.h - h + 1))

      expect(marginViolations([{ id: 'l', kind: 'text', rect: { x, y, w, h } }], format), `${format} ${x},${y} ${w}x${h}`).toEqual([])
    }
  })

  it('and a layer pushed one pixel into a band always fails', () => {
    const rnd = lcg(7)

    for (let i = 0; i < 500; i++) {
      const format = (Object.keys(FORMATS) as FormatKey[])[Math.floor(rnd() * 4)]!
      const live = liveArea(format)
      const layer: Layer = { id: 'l', kind: 'text', rect: { x: live.x - 1, y: live.y + Math.floor(rnd() * live.h) , w: 10, h: 1 } }

      expect(marginViolations([layer], format).length).toBe(1)
    }
  })
})
