import { contrast, type RGB } from './color'
import { H, MIN_CONTRAST, SAFE, W } from './constants'
import { worstLumaUnder } from './frame'
import type { Archetype, Genome } from './genome'
import { fitLines, type MeasureWidth } from './layout'
import type { Roles } from './palette'

/**
 * CREATIVE-ENGINE-001 — the layout solver: a genome becomes elements with real boxes, then the hard gate reads those boxes.
 *
 * Every text block is measured with the caller's measure (a canvas in the real font), wrapped with the orphan rule and fitted to at
 * most three lines; blocks stack in a band chosen away from the product (`choose_band`), aligned to the reading side in Arabic. The
 * sizes respect the floors: headline ≥ 4.5% of the height, sub-line ≥ 2.6%, CTA ≥ 9% of the width tall.
 */
export interface Box { x: number; y: number; w: number; h: number }

export type Role = 'hook' | 'message' | 'support' | 'price' | 'cta' | 'panel' | 'ribbon' | 'band' | 'frame' | 'brackets' | 'halo' | 'scrim'

export interface El {
  role: Role
  box: Box
  lines?: string[]
  px?: number
  font?: 'display' | 'text'
  color?: string
  /** A fill drawn behind the text (chip, pill, badge, panel), with the colour the text is measured against. */
  fill?: string | null
  fillRgb?: RGB
  radius?: number
  align?: 'start' | 'center'
  rotate?: number
  /** Ornaments (frames, brackets, halos, scrims) may cross the safe margins; ink may not. */
  decorative?: boolean
}

export interface Copy { hook: string; message: string; cta: string; support?: string; price?: string }

export interface Context {
  copy: Copy
  roles: Roles
  measure: MeasureWidth
  locale: 'ar' | 'en'
  /** The product box in frame pixels, from the saliency map. */
  product: Box
  /** Edge strength (W/8 × H/8) and luma of the fitted frame. */
  edges: Float32Array
  luma: Float32Array
  /** «ملصق الدعوة»: the CTA as a white sticker. */
  ctaSticker?: boolean
}

export const MIN_HOOK_PX = Math.ceil(H * 0.045)
export const MIN_SUB_PX = Math.ceil(H * 0.026)
export const MIN_CTA_H = Math.ceil(W * 0.09)
const LIVE_W = SAFE.x1 - SAFE.x0
const GAP = 22
const LINE = 1.25
/** Set by `solve` for the call in progress: Arabic aligns blocks to the right edge of the live area, English to the left. */
let RTL = true

const lineH = (px: number) => px * LINE

function textBlock(ctx: Context, role: Role, text: string, startPx: number, maxW: number, font: 'display' | 'text', floor: number): El | null {
  if (!text.trim()) return null
  const fit = fitLines(text, startPx, maxW, ctx.measure)
  const px = Math.max(floor, fit.px)
  const lines = px === fit.px ? fit.lines : fitLines(text, px, maxW, ctx.measure).lines
  const w = Math.min(maxW, Math.max(...lines.map((l) => ctx.measure(l, px))))

  return { role, lines, px, font, box: { x: 0, y: 0, w, h: lines.length * lineH(px) }, color: '#FFFFFF' }
}

function chip(ctx: Context, role: Role, text: string, px: number, fill: string, fillRgb: RGB, color: string, radius: number): El | null {
  if (!text.trim()) return null
  const tw = ctx.measure(text, px)
  const w = Math.min(LIVE_W, tw + px * 1.8)
  const h = Math.max(role === 'cta' ? MIN_CTA_H : 0, px * 2.1)

  return { role, lines: [text], px, font: 'display', fill, fillRgb, color, radius, box: { x: 0, y: 0, w, h } }
}

function cta(ctx: Context): El {
  const r = ctx.roles

  return ctx.ctaSticker
    ? chip(ctx, 'cta', `↗ ${ctx.copy.cta}`, 46, '#FFFFFF', [255, 255, 255], '#141414', 22)!
    : chip(ctx, 'cta', `${ctx.copy.cta}  ${ctx.locale === 'ar' ? '←' : '→'}`, 46, r.accent, r.accentRgb, r.onAccent, 999)!
}

/** Stack blocks from a top edge, each aligned to the reading side (or centred). Returns the stacked elements. */
function stack(items: El[], top: number, align: 'start' | 'center', anchorX?: number): El[] {
  let y = top
  const rtl = RTL

  return items.map((el) => {
    const x = align === 'center'
      ? (anchorX ?? W / 2) - el.box.w / 2
      : rtl ? (anchorX ?? SAFE.x1) - el.box.w : (anchorX ?? SAFE.x0)
    const placed = { ...el, align, box: { ...el.box, x, y } }
    y += el.box.h + GAP

    return placed
  })
}

const heightOf = (items: El[]) => items.reduce((n, e) => n + e.box.h, 0) + GAP * Math.max(0, items.length - 1)
const compact = (items: Array<El | null>): El[] => items.filter((e): e is El => e !== null)

/**
 * The free spans of the live area once the product (plus 24px) is taken out: above it and below it. A stack goes whole into the span
 * the archetype prefers when it fits; otherwise the text goes into one span and the CTA into the other — the reference's rule that no
 * ink ever lands on the product, applied before the gate rather than left for it to reject.
 */
function spans(product: Box): Array<{ y0: number; y1: number }> {
  const top = { y0: SAFE.y0, y1: Math.min(SAFE.y1, product.y - 24) }
  const bottom = { y0: Math.max(SAFE.y0, product.y + product.h + 24), y1: SAFE.y1 }
  const out = [top, bottom].filter((r) => r.y1 - r.y0 > 0)

  return out.length ? out : [{ y0: SAFE.y0, y1: SAFE.y1 }]
}

function placeStack(items: El[], product: Box, prefer: 'top' | 'bottom' | 'middle', align: 'start' | 'center', anchorX?: number): El[] {
  const regs = spans(product)
  const need = heightOf(items)
  const fits = regs.filter((r) => r.y1 - r.y0 >= need)
  if (fits.length) {
    const r = prefer === 'top' ? fits[0]! : prefer === 'bottom' ? fits[fits.length - 1]! : fits.reduce((a, b) => (b.y1 - b.y0 > a.y1 - a.y0 ? b : a))
    const top = prefer === 'top' ? r.y0 + 20 : prefer === 'bottom' ? r.y1 - need : r.y0 + (r.y1 - r.y0 - need) / 2

    return stack(items, Math.max(r.y0, Math.min(top, r.y1 - need)), align, anchorX)
  }
  const ctaEl = items.find((e) => e.role === 'cta')
  const text = items.filter((e) => e !== ctaEl)
  const textNeed = heightOf(text)
  for (const r of [...regs].sort((a, b) => (b.y1 - b.y0) - (a.y1 - a.y0))) {
    if (r.y1 - r.y0 < textNeed) continue
    const other = regs.find((o) => o !== r && ctaEl && o.y1 - o.y0 >= ctaEl.box.h)
    if (ctaEl && !other) continue
    const above = r.y1 <= product.y
    const placedText = stack(text, above ? r.y1 - textNeed : r.y0, align, anchorX)
    if (!ctaEl || !other) return placedText
    const ctaTop = other.y0 >= product.y ? other.y1 - ctaEl.box.h : other.y0 + 10

    return [...placedText, ...stack([ctaEl], ctaTop, 'center')]
  }

  // Neither fits whole: split the sequence where it reads naturally, the leading blocks (hook) above the product and the rest
  // (message, CTA) below it — the classic top-hook, bottom-call layout. Fixed decorative shells stay with the block they frame.
  if (regs.length === 2) {
    const [up, down] = regs as [{ y0: number; y1: number }, { y0: number; y1: number }]
    for (let k = items.length - 1; k >= 1; k--) {
      const head = items.slice(0, k)
      const tail = items.slice(k)
      if (heightOf(head) <= up.y1 - up.y0 && heightOf(tail) <= down.y1 - down.y0) {
        return [...stack(head, up.y1 - heightOf(head), align, anchorX), ...stack(tail, down.y0 + Math.max(0, (down.y1 - down.y0 - heightOf(tail)) * 0.35), align, anchorX)]
      }
    }
  }

  return stack(items, SAFE.y1 - need, align, anchorX)
}

function priceText(c: Copy): string | null {
  return c.price?.trim() || null
}

/** Which archetypes this content can carry at all. */
export function eligible(a: Archetype, c: Copy): boolean {
  if (a === 'number-hero') return !!priceText(c) || /\d+\s*[%٪]/.test(`${c.hook} ${c.support ?? ''}`)
  if (a === 'offer-badge') return !!(priceText(c) || c.support?.trim())

  return true
}

export function solve(g: Genome, ctx: Context): El[] {
  RTL = ctx.locale === 'ar'
  const { copy, roles } = ctx
  const hookPx = { 'bold-top-hook': 132, 'word-cascade': 140, 'number-hero': 96, 'minimal-center': 112 }[g.archetype as string] ?? 112
  const hook = (maxW = LIVE_W * 0.86) => textBlock(ctx, 'hook', copy.hook, hookPx, maxW, 'display', MIN_HOOK_PX)
  const message = (maxW = LIVE_W * 0.86) => textBlock(ctx, 'message', copy.message, 56, maxW, 'text', MIN_SUB_PX)
  const support = () => (copy.support?.trim() ? chip(ctx, 'support', copy.support, 40, roles.panel, roles.panelRgb, roles.onPanel, 14) : null)
  const a = g.archetype

  const shapeBehind = (block: El[]): El[] => {
    if (g.shape !== 'glass' && g.shape !== 'solid') return []
    const x0 = Math.min(...block.map((e) => e.box.x)) - 28
    const y0 = Math.min(...block.map((e) => e.box.y)) - 28
    const x1 = Math.max(...block.map((e) => e.box.x + e.box.w)) + 28
    const y1 = Math.max(...block.map((e) => e.box.y + e.box.h)) + 28
    const fill = g.shape === 'glass' ? 'rgba(0,0,0,0.45)' : roles.panel
    const fillRgb: RGB = g.shape === 'glass' ? [0, 0, 0] : roles.panelRgb

    return [{ role: 'panel', box: { x: Math.max(SAFE.x0, x0), y: Math.max(SAFE.y0, y0), w: Math.min(SAFE.x1, x1) - Math.max(SAFE.x0, x0), h: Math.min(SAFE.y1, y1) - Math.max(SAFE.y0, y0) }, fill, fillRgb, radius: 28 }]
  }
  // Text sitting on a solid panel takes the panel's readable colour.
  const onPanel = (els: El[], panels: El[]) => (panels.length && g.shape === 'solid' ? els.map((e) => (e.fill ? e : { ...e, color: roles.onPanel, fillRgb: roles.panelRgb })) : els)

  const P = ctx.product
  const withCta = (items: Array<El | null>) => [...compact(items), cta(ctx)]
  const panelOver = (block: El[]) => { const panels = shapeBehind(block.filter((e) => e.role !== 'cta')); return [...panels, ...onPanel(block, panels)] }

  switch (a) {
    case 'bold-top-hook':
      return panelOver(placeStack(withCta([hook(LIVE_W), message(LIVE_W * 0.9)]), P, 'top', 'center'))
    case 'minimal-center':
      return placeStack(withCta([hook(LIVE_W * 0.8)]), P, 'bottom', 'center')
    case 'surface-card': {
      const inner = withCta([hook(LIVE_W - 80), message(LIVE_W - 80)])
      const ph = heightOf(inner) + 70
      const reg = spans(P).filter((r) => r.y1 - r.y0 >= ph).at(-1)
      if (!reg) return placeStack(inner, P, 'bottom', 'start')
      const py = reg.y1 - ph
      const panel: El = { role: 'panel', box: { x: SAFE.x0, y: py, w: LIVE_W, h: ph }, fill: roles.panel, fillRgb: roles.panelRgb, radius: 28 }
      const placed = stack(inner, py + 35, 'start', RTL ? SAFE.x1 - 40 : SAFE.x0 + 40).map((e) => (e.role === 'cta' ? e : { ...e, color: roles.onPanel, fillRgb: roles.panelRgb }))

      return [panel, ...placed]
    }
    case 'editorial-frame':
      return [{ role: 'frame', box: { x: SAFE.x0, y: SAFE.y0, w: LIVE_W, h: SAFE.y1 - SAFE.y0 }, decorative: true, color: roles.accent }, ...placeStack(withCta([hook(LIVE_W * 0.78), message(LIVE_W * 0.76)]), P, 'bottom', 'center')]
    case 'corner-brackets': {
      const block = placeStack(withCta([hook(LIVE_W * 0.74), message(LIVE_W * 0.72)]), P, 'middle', 'center')
      const text = block.filter((e) => e.role !== 'cta')
      if (text.length === 0) return block
      const x0 = Math.max(SAFE.x0 - 20, Math.min(...text.map((e) => e.box.x)) - 36)
      const y0 = Math.min(...text.map((e) => e.box.y)) - 30
      const x1 = Math.min(SAFE.x1 + 20, Math.max(...text.map((e) => e.box.x + e.box.w)) + 36)
      const y1 = Math.max(...text.map((e) => e.box.y + e.box.h)) + 30

      return [{ role: 'brackets', box: { x: x0, y: y0, w: x1 - x0, h: y1 - y0 }, decorative: true, color: roles.accent }, ...block]
    }
    case 'ribbon-banner': {
      const h = hook(LIVE_W - 60)
      if (!h) return []
      const ribbonH = h.box.h + 40
      const shell: El = { ...h, role: 'hook', box: { ...h.box, w: LIVE_W, h: ribbonH } }
      const placed = placeStack([shell, ...withCta([message(LIVE_W * 0.86)])], P, 'top', 'center')
      const at = placed[0]!.box.y
      const ribbon: El = { role: 'ribbon', box: { x: SAFE.x0, y: at, w: LIVE_W, h: ribbonH }, fill: roles.accent, fillRgb: roles.accentRgb, radius: 8 }
      const hk = stack([{ ...h, color: roles.onAccent, fillRgb: roles.accentRgb }], at + 20, 'center')

      return [ribbon, ...hk, ...placed.slice(1)]
    }
    case 'diagonal-band': {
      const h = hook(LIVE_W * 0.8)
      if (!h) return []
      const bandH = h.box.h + 48
      const shell: El = { ...h, box: { ...h.box, w: LIVE_W - 60, h: bandH } }
      const placed = placeStack([shell, ...withCta([message(LIVE_W * 0.86)])], P, 'middle', 'center')
      const at = placed[0]!.box.y
      const strip: El = { role: 'band', box: { x: SAFE.x0 + 30, y: at, w: LIVE_W - 60, h: bandH }, fill: roles.accent, fillRgb: roles.accentRgb, rotate: -0.04, radius: 6 }
      const hk = stack([{ ...h, color: roles.onAccent, fillRgb: roles.accentRgb, rotate: -0.04 }], at + 24, 'center')

      return [strip, ...hk, ...placed.slice(1)]
    }
    case 'number-hero': {
      const p = priceText(copy) ?? (/\d+\s*[%٪]/.exec(`${copy.hook} ${copy.support ?? ''}`)?.[0] ?? '')
      const big = textBlock(ctx, 'price', p, 200, LIVE_W * 0.9, 'display', MIN_HOOK_PX)

      return panelOver(placeStack(withCta([hook(LIVE_W * 0.8), big]), P, 'bottom', 'center'))
    }
    case 'offer-badge': {
      const badgeText = priceText(copy) ?? copy.support ?? ''
      const badge = chip(ctx, 'price', badgeText, 64, roles.accent, roles.accentRgb, roles.onAccent, 18)

      return panelOver(placeStack(withCta([hook(), badge, message()]), P, 'bottom', 'start'))
    }
    case 'word-cascade': {
      const words = copy.hook.trim().split(/\s+/u).slice(0, 4)
      const els = compact(words.map((w) => textBlock(ctx, 'hook', w, 150, LIVE_W * 0.8, 'display', MIN_HOOK_PX)))
      const placed = placeStack([...els, ...withCta([message(LIVE_W * 0.8)])], P, 'top', 'start')

      // The cascade steps each word in from the reading edge.
      return placed.map((e, i) => (i < els.length && i > 0 ? { ...e, box: { ...e.box, x: RTL ? e.box.x - i * 36 : e.box.x + i * 36 } } : e))
    }
    case 'product-spotlight': {
      const halo: El = { role: 'halo', box: { x: P.x - 80, y: P.y - 80, w: P.w + 160, h: P.h + 160 }, decorative: true }

      return [halo, ...placeStack(withCta([hook(LIVE_W * 0.84), message(LIVE_W * 0.8)]), P, 'bottom', 'center')]
    }
    case 'side-stack':
    default:
      return panelOver(placeStack(withCta([hook(LIVE_W * 0.74), message(LIVE_W * 0.7), support()]), P, 'middle', 'start'))
  }
}

/** Adds a soft scrim under bare white text that would not reach 4.5:1 on the picture (`ensure_contrast`). */
export function ensureContrast(els: El[], ctx: Context): El[] {
  const bare = els.filter((e) => e.lines && !e.fill && !e.fillRgb)
  if (bare.length === 0) return els
  const needs = bare.some((e) => contrast([255, 255, 255], grey(worstLumaUnder(ctx.luma, e.box))) < MIN_CONTRAST)
  if (!needs) return els
  const y0 = Math.min(...bare.map((e) => e.box.y))
  const y1 = Math.max(...bare.map((e) => e.box.y + e.box.h))

  return [{ role: 'scrim', box: { x: 0, y: y0 - 140, w: W, h: y1 - y0 + 280 }, decorative: true, fill: 'rgba(0,0,0,0.62)' }, ...els]
}

const grey = (v: number): RGB => [v, v, v]
