import type { Ctx2D } from './render'
import type { Scene, SceneLayer } from './scene'
import { DISPLAY_WEIGHT, TEXT_WEIGHT } from '../ui/fonts'

/**
 * CONTENT-LIBRARY-SNAP-001 — how a design is dressed, beyond where its blocks sit.
 *
 * Owner 2026-11-22: the layouts were right but read as primitive — plain text on the picture and square boxes. A look is the
 * finishing the platforms' own templates have: a tight highlight behind each line («marker»), a glass card with a soft scrim
 * («glass»), or a heavy headline over a gradient («bold»); the call to action is a rounded pill with an arrow, or a sticker; a price
 * is a tilted round badge. Nothing here moves a block: every box is drawn inside the rect the placement (and the safe margins) gave it.
 */
export type Look = 'marker' | 'glass' | 'bold'

export interface SceneStyle {
  look: Look
  /** «ملصق الدعوة»: the CTA as a white sticker with a link mark instead of the accent pill. */
  ctaSticker?: boolean
  /** «تأثيرات الفيديو»: a little more contrast and colour on the picture itself. */
  colorBoost?: boolean
}

/** Which look each system family wears; a family not listed wears «glass». */
export const LOOK_BY_FAMILY: Record<string, Look> = {
  'bold-hook': 'bold',
  'detail-cuts': 'bold',
  'stacked-reveal': 'marker',
  split: 'marker',
  'offer-card': 'marker',
  'editorial-frame': 'glass',
  minimal: 'glass',
}

const canStyle = (ctx: Ctx2D): boolean => typeof ctx.measureText === 'function'

function box(ctx: Ctx2D, x: number, y: number, w: number, h: number, r: number): void {
  if (typeof ctx.roundRect === 'function' && typeof ctx.fill === 'function') {
    ctx.beginPath()
    ctx.roundRect(x, y, w, h, r)
    ctx.fill()
  } else {
    ctx.fillRect(x, y, w, h)
  }
}

function shadow(ctx: Ctx2D, on: boolean, blur = 18): void {
  if (!('shadowBlur' in ctx)) return
  ctx.shadowColor = on ? 'rgba(0,0,0,0.45)' : 'transparent'
  ctx.shadowBlur = on ? blur : 0
  ctx.shadowOffsetY = on ? 4 : 0
}

/** The scrims a look lays over the picture before any text: a gradient, never a band of solid colour. */
export function drawScrim(ctx: Ctx2D, scene: Scene, style: SceneStyle | undefined): void {
  if (!style || style.look === 'marker' || typeof ctx.createLinearGradient !== 'function') return
  const { width, height } = scene
  const bottom = ctx.createLinearGradient(0, height * 0.5, 0, height)
  bottom.addColorStop(0, 'rgba(0,0,0,0)')
  bottom.addColorStop(1, 'rgba(0,0,0,0.6)')
  ctx.save()
  ctx.fillStyle = bottom as unknown as string
  ctx.fillRect(0, height * 0.5, width, height * 0.5)
  if (style.look === 'bold') {
    const top = ctx.createLinearGradient(0, 0, 0, height * 0.35)
    top.addColorStop(0, 'rgba(0,0,0,0.55)')
    top.addColorStop(1, 'rgba(0,0,0,0)')
    ctx.fillStyle = top as unknown as string
    ctx.fillRect(0, 0, width, height * 0.35)
  }
  ctx.restore()
}

/**
 * Draws one layer in its look, in the layer's own centred coordinates (the caller has translated and scaled for the motion).
 * Returns false when the context cannot measure text (a test stub), so the caller keeps its plain drawing.
 */
export function drawStyledLayer(ctx: Ctx2D, layer: SceneLayer, scene: Scene, style: SceneStyle, fonts: { display: string; text: string }): boolean {
  if (!canStyle(ctx) || (layer.lines.length === 0 && layer.fill === null)) return false

  const w = layer.rect.w
  const h = layer.rect.h
  const family = layer.font === 'display' ? fonts.display : fonts.text
  const weight = layer.font === 'display' ? DISPLAY_WEIGHT : TEXT_WEIGHT
  // Never wider than the block: the weight drawn is heavier than the one the placement measured, so the size is checked again here
  // against the real glyphs and brought down until the widest line (plus its padding) fits. A word is never cut at the canvas edge.
  let px = layer.fontPx
  ctx.font = `${weight} ${px}px ${family}`
  const widest = Math.max(1, ...layer.lines.map((l) => ctx.measureText!(l).width))
  const room = w - px * (layer.role === 'cta' ? 2.4 : 0.9)
  if (widest > room && room > 0) px = Math.max(12, Math.floor(px * (room / widest)))
  const font = `${weight} ${px}px ${family}`
  const lineH = px * 1.25
  const top = -((layer.lines.length - 1) * lineH) / 2
  const widthOf = (s: string) => Math.min(w, ctx.measureText!(s).width)
  const arrow = scene.direction === 'rtl' ? '←' : '→'

  ctx.direction = scene.direction
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.font = font

  if (layer.role === 'surface') {
    ctx.fillStyle = layer.fill ?? 'rgba(0,0,0,0.5)'
    box(ctx, -w / 2, -h / 2, w, h, Math.min(w, h) * 0.08)

    return true
  }

  if (layer.role === 'cta') {
    const label = style.ctaSticker ? `↗ ${layer.lines.join(' ')}` : `${layer.lines.join(' ')}  ${arrow}`
    const pw = Math.min(w, widthOf(label) + px * 2)
    const ph = Math.min(h, px * 2.1)
    ctx.fillStyle = style.ctaSticker ? '#ffffff' : (layer.fill ?? '#111111')
    shadow(ctx, true, 22)
    box(ctx, -pw / 2, -ph / 2, pw, ph, ph / 2)
    shadow(ctx, false)
    ctx.fillStyle = style.ctaSticker ? '#111111' : layer.color
    ctx.fillText(label, 0, 0)

    return true
  }

  if (layer.role === 'price') {
    const text = layer.lines.join(' ')
    const r = Math.min(Math.min(w, h) / 2, Math.max(widthOf(text) / 2 + px * 0.6, px * 1.3))
    ctx.save()
    if (typeof ctx.rotate === 'function') ctx.rotate(-0.12)
    shadow(ctx, true)
    ctx.fillStyle = '#ffffff'
    box(ctx, -r - 6, -r - 6, (r + 6) * 2, (r + 6) * 2, r + 6)
    shadow(ctx, false)
    ctx.fillStyle = layer.fill ?? '#111111'
    box(ctx, -r, -r, r * 2, r * 2, r)
    ctx.fillStyle = layer.color
    ctx.fillText(text, 0, 0)
    ctx.restore()

    return true
  }

  const isHook = layer.role === 'hook'

  if (style.look === 'marker') {
    // A tight rounded highlight behind each line: the accent behind the hook, white behind the rest.
    layer.lines.forEach((line, i) => {
      const lw = widthOf(line) + px * 0.7
      const y = top + i * lineH
      ctx.fillStyle = isHook ? '#FFE14D' : '#ffffff'
      box(ctx, -lw / 2, y - px * 0.62, lw, px * 1.24, px * 0.22)
      ctx.fillStyle = '#111111'
      ctx.fillText(line, 0, y)
    })

    return true
  }

  if (style.look === 'glass' && !isHook) {
    const cw = Math.min(w, Math.max(...layer.lines.map(widthOf)) + px * 1.4)
    const ch = Math.min(h, layer.lines.length * lineH + px * 0.8)
    ctx.fillStyle = 'rgba(0,0,0,0.42)'
    box(ctx, -cw / 2, -ch / 2, cw, ch, px * 0.5)
  }

  ctx.fillStyle = '#ffffff'
  shadow(ctx, true, isHook ? 24 : 12)
  layer.lines.forEach((line, i) => ctx.fillText(line, 0, top + i * lineH))
  shadow(ctx, false)

  return true
}
