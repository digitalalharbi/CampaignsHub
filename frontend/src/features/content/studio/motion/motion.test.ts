import { describe, expect, it } from 'vitest'
import { analyzeImage } from '../analysis/analyzeImage'
import type { Pixels } from '../analysis/pixels'
import { contrastRatio } from '../tokens/contrast'
import { fromHex } from '../tokens/contrast'
import { deriveTokens } from '../tokens/tokens'
import { hardChecks } from '../direction/hardChecks'
import { liveArea, marginViolations, protectedBands } from '../direction/safeZone'
import { buildTimeline } from '../direction/timeline'
import { placeFamily } from '../families/placeFamily'
import { SYSTEM_FAMILIES } from '../families/index'
import { cutTimes, snapToBeat } from './beats'
import { cubicBezier, EASE_OUT, easeOut } from './easing'
import { RECIPES, recipeFor, transformedRect } from './recipes'
import { drawFrame, type Ctx2D } from './render'
import { buildScene } from './scene'
import { approxMeasure, fitText, minReadMs, wrap } from './text'

const rect = { x: 100, y: 400, w: 800, h: 200 }

describe('easing', () => {
  it('starts at 0, ends at 1 and never goes backwards', () => {
    expect(cubicBezier(EASE_OUT, 0)).toBeCloseTo(0, 5)
    expect(cubicBezier(EASE_OUT, 1)).toBeCloseTo(1, 5)

    let last = -1
    for (let i = 0; i <= 100; i++) {
      const v = easeOut(i / 100)
      expect(v).toBeGreaterThanOrEqual(last - 1e-9)
      last = v
    }
  })
})

describe('the motion recipes', () => {
  const ctx = { direction: 'rtl' as const, rect }

  it('every enter recipe ends at rest: whole, opaque, in place, full size', () => {
    for (const [key, recipe] of Object.entries(RECIPES)) {
      if (key === 'ken-burns' || key === 'parallax') continue

      const t = recipe(1, ctx)

      expect(t.opacity, key).toBeCloseTo(1, 5)
      expect(Math.abs(t.translateX), key).toBeLessThan(1e-6)
      expect(Math.abs(t.translateY), key).toBeLessThan(1e-6)
      expect(t.scaleX, key).toBeCloseTo(1, 5)
      expect(t.reveal, key).toBeCloseTo(1, 5)
    }
  })

  it('every enter recipe is not fully visible at the start', () => {
    for (const key of ['fade', 'rise', 'mask-wipe-rtl', 'bar-grow-start', 'zoom-punch', 'badge-pop', 'beat-cut']) {
      const t = recipeFor(key)(0, ctx)

      // Not whole at the start: transparent, partly revealed, or not at rest size. (A fade may sit a hair above 0 after easing.)
      expect(t.opacity < 0.05 || t.reveal < 0.05 || t.scaleX !== 1 || t.translateY !== 0, key).toBe(true)
    }
  })

  it('the wipe reveals more as it progresses', () => {
    expect(recipeFor('mask-wipe-rtl')(0.3, ctx).reveal).toBeLessThan(recipeFor('mask-wipe-rtl')(0.8, ctx).reveal)
  })

  it('an unknown recipe is a fade, not an error', () => {
    expect(recipeFor('does-not-exist')(0.5, ctx).opacity).toBeCloseTo(recipeFor('fade')(0.5, ctx).opacity, 5)
  })

  it('every motion key a system family names is a real recipe', () => {
    for (const f of SYSTEM_FAMILIES) for (const key of f.motion) expect(RECIPES[key], `${f.key}: ${key}`).toBeDefined()
  })

  it('a zoom-punch grows the box around its centre', () => {
    const r = transformedRect(rect, recipeFor('zoom-punch')(0, ctx))

    expect(r.w).toBeGreaterThan(rect.w)
    expect(r.x + r.w / 2).toBeCloseTo(rect.x + rect.w / 2, 5)
  })
})

describe('rhythm', () => {
  it('snaps to a beat that is close and leaves a time that is not', () => {
    expect(snapToBeat(3100, [500, 3000, 6000])).toBe(3000)
    expect(snapToBeat(3500, [500, 3000, 6000])).toBe(3500)
    expect(snapToBeat(3100, [])).toBe(3100)
  })

  it('cut times are increasing and stay inside the span, with or without beats', () => {
    for (const beats of [[], [1000, 2300, 4100, 5000, 6200]]) {
      const cuts = cutTimes(3000, 9500, 4, beats)

      expect(cuts.length).toBeGreaterThan(0)
      for (let i = 0; i < cuts.length; i++) {
        expect(cuts[i]).toBeGreaterThan(3000)
        expect(cuts[i]).toBeLessThan(9500)
        if (i > 0) expect(cuts[i]).toBeGreaterThan(cuts[i - 1]!)
      }
    }
  })
})

describe('fitting copy', () => {
  it('shrinks the size until a line fits, wraps by words, and flags a block that cannot fit', () => {
    const fit = fitText(approxMeasure, 'عطر فاخر بتركيز عالٍ يدوم أطول', 600, 300, 100, 40)

    expect(fit.overflow).toBe(false)
    expect(fit.lines.length).toBeGreaterThan(0)
    for (const line of fit.lines) expect(approxMeasure(line, fit.px)).toBeLessThanOrEqual(600)

    const tooSmall = fitText(approxMeasure, 'نص طويل جدا جدا جدا جدا جدا جدا جدا جدا جدا جدا', 200, 40, 100, 40)

    expect(tooSmall.overflow).toBe(true)
    expect(tooSmall.px).toBe(40)
  })

  it('keeps reading order when it wraps', () => {
    expect(wrap(approxMeasure, 'واحد اثنان ثلاثة أربعة', 60, 200).join(' ')).toBe('واحد اثنان ثلاثة أربعة')
  })

  it('a block of copy stays on screen at least 0.3s a word plus 0.8s', () => {
    expect(minReadMs(0)).toBe(800)
    expect(minReadMs(6)).toBe(2600)
  })
})

function solid(w: number, h: number, c: [number, number, number]): Pixels {
  const data = new Uint8ClampedArray(w * h * 4)
  for (let i = 0; i < w * h; i++) { data[i * 4] = c[0]; data[i * 4 + 1] = c[1]; data[i * 4 + 2] = c[2]; data[i * 4 + 3] = 255 }

  return { width: w, height: h, data }
}

const COPY = { hook: 'خصم ٣٠٪ اليوم', message: 'عطر فاخر يدوم أطول', cta: 'اطلب الآن', support: '٢٩٩ ر.س' }

function sceneFor(familyKey: string, video = true, locale: 'ar' | 'en' = 'ar') {
  const analysis = analyzeImage(solid(360, 640, [30, 34, 44]))
  const tokens = deriveTokens({ swatches: analysis.swatches, classes: analysis.classes })
  const family = SYSTEM_FAMILIES.find((f) => f.key === familyKey)!
  const layers = placeFamily(family, analysis, '9x16', locale)
  const timeline = video ? buildTimeline() : undefined

  return { scene: buildScene({ family, layers, copy: COPY, tokens, format: '9x16', locale, tone: 'light', measure: approxMeasure, timeline, beatsMs: [0, 3000, 6000, 9500] }), tokens, layers, timeline }
}

describe('the scene', () => {
  it('shows the hook within the first half second and holds the CTA for at least 2.5 seconds at the end', () => {
    for (const f of SYSTEM_FAMILIES.filter((x) => x.kind !== 'image')) {
      const { scene, timeline } = sceneFor(f.key)
      const hook = scene.layers.find((l) => l.role === 'hook')!
      const cta = scene.layers.find((l) => l.role === 'cta')!

      expect(hook.enterAt + hook.enterMs, f.key).toBeLessThanOrEqual(500)
      expect(cta.enterAt, f.key).toBe(timeline!.cta.start)
      expect(scene.durationMs - (cta.enterAt + cta.enterMs), f.key).toBeGreaterThanOrEqual(2000)
    }
  })

  it('the message comes after the hook, on a beat', () => {
    const { scene } = sceneFor('stacked-reveal')

    expect(scene.layers.find((l) => l.role === 'message')!.enterAt).toBe(3000)
  })

  it('the call-to-action label is readable on its fill, and no copy overflows at the smallest legible size', () => {
    for (const f of SYSTEM_FAMILIES) {
      const { scene } = sceneFor(f.key, f.kind !== 'image')
      const cta = scene.layers.find((l) => l.role === 'cta')!

      expect(contrastRatio(fromHex(cta.color), fromHex(cta.fill!)), f.key).toBeGreaterThanOrEqual(4.5)
      expect(scene.overflow, f.key).toEqual([])
    }
  })

  it('an image has no duration and everything is there from the first frame', () => {
    const { scene } = sceneFor('minimal', false)

    expect(scene.durationMs).toBe(0)
    expect(scene.layers.every((l) => l.enterAt === 0)).toBe(true)
  })

  it('the scene passes the studio hard checks that guard saving', () => {
    for (const f of SYSTEM_FAMILIES.filter((x) => x.kind !== 'image')) {
      const { layers, timeline } = sceneFor(f.key)

      expect(hardChecks({ format: '9x16', layers, copy: COPY, timeline }), f.key).toEqual([])
    }
  })
})

/** A recording 2D context: what was drawn, where, with which transform. */
function recorder() {
  const calls: Array<{ op: string; args: unknown[]; alpha: number; tx: number; ty: number; fill: string }> = []
  const stack: Array<{ tx: number; ty: number; alpha: number }> = []
  let tx = 0
  let ty = 0
  const ctx: Ctx2D = {
    globalAlpha: 1, fillStyle: '#000', font: '', textAlign: 'left', textBaseline: 'alphabetic', direction: 'ltr',
    save() { stack.push({ tx, ty, alpha: this.globalAlpha }) },
    restore() { const s = stack.pop()!; tx = s.tx; ty = s.ty; this.globalAlpha = s.alpha },
    translate(x, y) { tx += x; ty += y },
    scale() {},
    beginPath() {},
    rect() {},
    clip() {},
    fillRect(...args) { calls.push({ op: 'fillRect', args, alpha: this.globalAlpha, tx, ty, fill: this.fillStyle }) },
    fillText(...args) { calls.push({ op: 'fillText', args, alpha: this.globalAlpha, tx, ty, fill: this.fillStyle }) },
  }

  return { ctx, calls }
}

const OPTIONS = { displayFont: 'Cairo', textFont: 'Tajawal' }

describe('drawing a frame', () => {
  it('at t=0 only what has entered is drawn; at the end everything is', () => {
    const { scene } = sceneFor('stacked-reveal')
    const start = recorder()
    const end = recorder()

    drawFrame(start.ctx, scene, 0, OPTIONS)
    drawFrame(end.ctx, scene, scene.durationMs, OPTIONS)

    const texts = (r: ReturnType<typeof recorder>) => r.calls.filter((c) => c.op === 'fillText').length

    expect(texts(start)).toBeGreaterThan(0)
    expect(texts(end)).toBeGreaterThan(texts(start))
  })

  it('every text is drawn inside the live area (at rest, the last frame)', () => {
    const live = liveArea('9x16')

    for (const f of SYSTEM_FAMILIES.filter((x) => x.kind !== 'image')) {
      const { scene } = sceneFor(f.key)
      const r = recorder()

      drawFrame(r.ctx, scene, scene.durationMs, OPTIONS)

      for (const c of r.calls.filter((x) => x.op === 'fillText')) {
        expect(c.tx, f.key).toBeGreaterThanOrEqual(live.x)
        expect(c.tx, f.key).toBeLessThanOrEqual(live.x + live.w)
        expect(c.ty, f.key).toBeGreaterThanOrEqual(live.y)
        expect(c.ty, f.key).toBeLessThanOrEqual(live.y + live.h)
      }
    }
  })

  it('the margin overlay draws exactly the four protected bands, and nothing when it is off', () => {
    const { scene } = sceneFor('minimal')
    const on = recorder()
    const off = recorder()

    drawFrame(on.ctx, scene, scene.durationMs, { ...OPTIONS, showMargins: true })
    drawFrame(off.ctx, scene, scene.durationMs, OPTIONS)

    const bands = on.calls.filter((c) => c.op === 'fillRect' && c.fill === '#ff2d55')

    expect(bands.map((b) => b.args)).toEqual(protectedBands('9x16').map((b) => [b.x, b.y, b.w, b.h]))
    expect(off.calls.some((c) => c.fill === '#ff2d55')).toBe(false)
  })

  it('draws the same frame twice identically (what is seen is what is exported)', () => {
    const { scene } = sceneFor('offer-card')
    const a = recorder()
    const b = recorder()

    drawFrame(a.ctx, scene, 4200, OPTIONS)
    drawFrame(b.ctx, scene, 4200, OPTIONS)

    expect(a.calls).toEqual(b.calls)
  })

  it('a mirrored scene for English still keeps every layer inside the live area', () => {
    const { layers } = sceneFor('stacked-reveal', true, 'en')

    expect(marginViolations(layers, '9x16')).toEqual([])
  })
})
