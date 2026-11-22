import { contrast, type RGB } from './color'
import { H, MIN_CONTRAST, SAFE, W } from './constants'
import { worstLumaUnder } from './frame'
import { MIN_CTA_H, MIN_HOOK_PX, MIN_SUB_PX, type Box, type Context, type El } from './solve'

/**
 * CREATIVE-ENGINE-001 — the hard gate. One broken rule and the candidate is dropped; it is never shown.
 *
 * Ink inside the live area (ornaments excepted) · no text over the product (+24px) · every text ≥ 4.5:1 against what is really behind
 * it (its own fill, or the picture under it — darkened by a scrim if one was added — read at its worst 20%) · at most three lines ·
 * headline ≥ 4.5% of the height, sub-line ≥ 2.6% · the CTA present and ≥ 9% of the width tall · a price carries its currency · no
 * hashtag or emoji · at most three colours besides the two neutrals · no opaque panel over more than 25% of the product.
 */
export interface Verdict { ok: boolean; reasons: string[]; minContrast: number }

const inter = (a: Box, b: Box) => Math.max(0, Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x)) * Math.max(0, Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y))
const grow = (b: Box, m: number): Box => ({ x: b.x - m, y: b.y - m, w: b.w + 2 * m, h: b.h + 2 * m })
const hexRgb = (h: string): RGB => {
  const s = h.replace('#', '')

  return [parseInt(s.slice(0, 2), 16), parseInt(s.slice(2, 4), 16), parseInt(s.slice(4, 6), 16)]
}
export const CURRENCY = /(ر\.س|ريال|SAR|SR|\$|﷼)/u
const HASHTAG = /(^|\s)#[\p{L}\p{N}_]/u
const EMOJI = /\p{Extended_Pictographic}/u

export function gate(els: El[], ctx: Context): Verdict {
  const reasons: string[] = []
  const texts = els.filter((e) => e.lines && e.lines.length > 0)
  const scrim = els.find((e) => e.role === 'scrim')
  let minContrast = Infinity

  for (const e of els) {
    if (e.decorative) continue
    const b = e.box
    if (b.x < SAFE.x0 - 0.5 || b.x + b.w > SAFE.x1 + 0.5 || b.y < SAFE.y0 - 0.5 || b.y + b.h > SAFE.y1 + 0.5) reasons.push(`safe:${e.role}`)
    if (b.x < 0 || b.y < 0 || b.x + b.w > W || b.y + b.h > H) reasons.push(`canvas:${e.role}`)
  }
  for (const e of texts) {
    if (inter(e.box, grow(ctx.product, 24)) > 0) reasons.push(`product:${e.role}`)
    if (e.lines!.length > 3) reasons.push(`lines:${e.role}`)
    if (e.role === 'hook' && (e.px ?? 0) < MIN_HOOK_PX) reasons.push('size:hook')
    if (e.role === 'message' && (e.px ?? 0) < MIN_SUB_PX) reasons.push('size:message')
    const text = e.lines!.join(' ')
    if (HASHTAG.test(text) || EMOJI.test(text)) reasons.push(`clean:${e.role}`)
    const fg = hexRgb(e.color ?? '#FFFFFF')
    let bg: RGB
    if (e.fillRgb) bg = e.fillRgb
    else {
      const v = worstLumaUnder(ctx.luma, e.box) * (scrim && inter(scrim.box, e.box) > 0 ? 1 - 0.62 : 1)
      bg = [v, v, v]
    }
    const c = contrast(fg, bg)
    minContrast = Math.min(minContrast, c)
    if (c < MIN_CONTRAST) reasons.push(`contrast:${e.role}`)
  }
  const ctaEl = els.find((e) => e.role === 'cta')
  if (!ctaEl) reasons.push('cta:missing')
  else if (ctaEl.box.h < MIN_CTA_H) reasons.push('cta:small')
  const price = els.find((e) => e.role === 'price')
  if (price && !CURRENCY.test(price.lines!.join(' ')) && !/[%٪]/.test(price.lines!.join(' '))) reasons.push('price:currency')
  const colours = new Set(els.flatMap((e) => [e.fill, e.color]).filter((c): c is string => !!c && c.startsWith('#') && !['#FFFFFF', '#141414'].includes(c.toUpperCase())))
  if (colours.size > 3) reasons.push('colours')
  const covered = els.filter((e) => (e.role === 'panel' || e.role === 'ribbon' || e.role === 'band') && e.fill && !e.fill.startsWith('rgba')).reduce((n, e) => n + inter(e.box, ctx.product), 0)
  if (covered > ctx.product.w * ctx.product.h * 0.25) reasons.push('product:covered')

  return { ok: reasons.length === 0, reasons, minContrast: minContrast === Infinity ? 0 : minContrast }
}
