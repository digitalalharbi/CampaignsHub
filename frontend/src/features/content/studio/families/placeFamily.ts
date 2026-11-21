import type { ImageAnalysis } from '../analysis/analyzeImage'
import { PRODUCT_AIR } from '../analysis/placement'
import { calmestBlock } from '../analysis/placement'
import { FORMATS, liveArea, type FormatKey, type Layer, type LayerKind, type Rect } from '../direction/safeZone'
import type { Anchor, Family, Role } from './familySchema'


/**
 * CONTENT-STUDIO-001 batch 4 — turning a family into layer rectangles for one picture and one format.
 *
 * The rule of the whole function: every layer ends INSIDE the live area (the batch-1 safe margins), calm blocks go where the picture is
 * quiet and away from the product, and no two text blocks overlap. The result is plain `Layer[]`, so the same hard checks that guard a
 * saved output run on it before anything is drawn.
 */
const GAP = 16

const KIND: Record<Role, LayerKind> = { logo: 'logo', hook: 'text', message: 'text', support: 'text', cta: 'cta', price: 'price', surface: 'bar' }

const clamp = (v: number, lo: number, hi: number): number => Math.min(Math.max(v, lo), Math.max(lo, hi))

/** The band of the live area a family's anchor prefers, so a calm search starts where the family wants to sit. */
function bandFor(anchor: Anchor, live: Rect): Rect {
  const third = live.h / 3
  const fifth = live.h / 5

  switch (anchor) {
    case 'top': return { ...live, h: third }
    case 'upper': return { ...live, y: live.y + fifth * 0.5, h: third + fifth * 0.5 }
    case 'middle': return { ...live, y: live.y + third, h: third }
    case 'lower': return { ...live, y: live.y + live.h - third - fifth * 0.5, h: third + fifth * 0.5 }
    case 'bottom': return { ...live, y: live.y + live.h - third, h: third }
    case 'start-side': return { ...live, w: live.w / 2 }
    case 'end-side': return { ...live, x: live.x + live.w / 2, w: live.w / 2 }
  }
}

const alignX = (align: 'start' | 'center' | 'end', width: number, live: Rect, locale: 'ar' | 'en'): number => {
  const startIsRight = locale === 'ar'
  const left = live.x
  const right = live.x + live.w - width

  if (align === 'center') return left + (live.w - width) / 2
  if (align === 'start') return startIsRight ? right : left

  return startIsRight ? left : right
}

export interface PlaceOptions {
  /** Roles with no copy to show (no price given, no support line): their blocks are left out so no empty badge is drawn and the column closes up. */
  skip?: ReadonlyArray<Role>
}

export function placeFamily(family: Family, analysis: ImageAnalysis, format: FormatKey, locale: 'ar' | 'en' = 'ar', options: PlaceOptions = {}): Layer[] {
  const canvas = FORMATS[format]
  const live = liveArea(format)
  const cw = canvas.width / analysis.grid.cols
  const ch = canvas.height / analysis.grid.rows
  const layers: Layer[] = []

  const size = (cols: number, rows: number) => ({ w: Math.min(cols * cw, live.w), h: Math.min(rows * ch, live.h) })
  const surface = family.blocks.find((b) => b.role === 'surface')
  let surfaceRect: Rect | null = null

  if (surface) {
    const s = size(surface.cols, surface.rows)
    const product = { x: analysis.product.x * canvas.width, y: analysis.product.y * canvas.height, w: analysis.product.w * canvas.width, h: analysis.product.h * canvas.height }
    const opposite = surface.align === 'start' ? 'end' : surface.align === 'end' ? 'start' : 'center'
    const own = { x: alignX(surface.align, s.w, live, locale), y: live.y, w: s.w, h: s.h }
    const other = { x: alignX(opposite, s.w, live, locale), y: live.y, w: s.w, h: s.h }
    const covered = (r: Rect): number => Math.max(0, Math.min(r.x + r.w, product.x + product.w) - Math.max(r.x, product.x)) * Math.max(0, Math.min(r.y + r.h, product.y + product.h) - Math.max(r.y, product.y))

    // A panel beside the picture goes on the side that covers less of the product; the family's own side wins a tie.
    surfaceRect = covered(other) < covered(own) ? other : own
    layers.push({ id: 'surface', kind: 'bar', rect: surfaceRect })
  }

  // Calm text blocks stack from one origin: the first is found in the picture, the rest follow it downward (or upward at the bottom).
  let stackX: number | null = null
  let stackTop = 0
  let stackBottom: number | null = null
  const stackLimit: Rect = surfaceRect ?? live

  for (const block of family.blocks) {
    if (block.role === 'surface' || options.skip?.includes(block.role)) continue

    const { w, h } = size(block.cols, block.rows)
    let rect: Rect

    if (block.place === 'live-top') rect = { x: alignX(block.align, w, live, locale), y: live.y, w, h }
    else if (block.place === 'live-bottom') rect = { x: alignX(block.align, w, live, locale), y: live.y + live.h - h, w, h }
    else if (block.place === 'live-center') rect = { x: alignX(block.align, w, live, locale), y: live.y + (live.h - h) / 2, w, h }
    else if (stackBottom === null || stackX === null) {
      if (surfaceRect) {
        rect = { x: surfaceRect.x + (surfaceRect.w - w) / 2, y: surfaceRect.y + GAP * 4, w: Math.min(w, surfaceRect.w), h }
      } else {
        const band = bandFor(family.anchor, live)
        let cols = block.cols
        let rows = block.rows
        let found = null as ReturnType<typeof calmestBlock>

        // Prefer the family's own band; widen to the whole live area; shrink the block rather than cover the product.
        for (let shrink = 0; shrink < 6 && found === null; shrink++) {
          const inBand = calmestBlock(analysis.grid, canvas, band, cols, rows, analysis.product)
          const anywhere = inBand === null || inBand.overlapsProduct ? calmestBlock(analysis.grid, canvas, live, cols, rows, analysis.product) : null

          // The family's own band wins unless it can only cover the product and the rest of the live area has a clear place.
          found = anywhere !== null && (inBand === null || !anywhere.overlapsProduct) ? anywhere : inBand
          if (found === null) { cols = Math.max(1, cols - 1); rows = Math.max(1, rows - 1) }
        }

        rect = found
          ? { x: found.col * cw, y: found.row * ch, w: found.cols * cw, h: found.rows * ch }
          : { x: live.x + (live.w - w) / 2, y: live.y + (live.h - h) / 2, w, h }
      }
      stackX = rect.x
    } else if (stackBottom + GAP + h <= stackLimit.y + stackLimit.h) {
      rect = { x: stackX, y: stackBottom + GAP, w, h }
    } else {
      // No room under the stack: continue upward from its top instead of running past the live area.
      rect = { x: stackX, y: stackTop - GAP - h, w, h }
    }

    // Always inside the live area — and inside the surface when the family has one.
    const box = block.role === 'cta' || block.role === 'logo' ? live : stackLimit
    rect = { ...rect, x: clamp(rect.x, box.x, box.x + box.w - rect.w), y: clamp(rect.y, box.y, box.y + box.h - rect.h) }

    if (block.place === 'calm') {
      if (stackBottom === null) {
        stackTop = rect.y
        stackBottom = rect.y + rect.h
      } else {
        stackTop = Math.min(stackTop, rect.y)
        stackBottom = Math.max(stackBottom, rect.y + rect.h)
      }
    }

    layers.push({ id: block.role, kind: KIND[block.role], rect })
  }

  const product: Rect = {
    x: analysis.product.x * canvas.width - cw * PRODUCT_AIR.x,
    y: analysis.product.y * canvas.height - ch * PRODUCT_AIR.y,
    w: analysis.product.w * canvas.width + cw * PRODUCT_AIR.x * 2,
    h: analysis.product.h * canvas.height + ch * PRODUCT_AIR.y * 2,
  }

  return resolveOverlaps(layers, live, product)
}

/**
 * The text blocks form one column. A pinned logo above or CTA below limits how much room that column has; the column keeps its order,
 * starts where the picture asked for it, slides up or down to fit between the pinned layers, and — only when it still does not fit —
 * every block is made proportionally shorter. So the result never overlaps and never leaves the live area.
 */
function resolveOverlaps(layers: Layer[], live: Rect, product: Rect): Layer[] {
  const out = layers.map((l) => ({ ...l, rect: { ...l.rect } }))
  const texts = out.filter((l) => l.kind === 'text' || l.kind === 'price').sort((a, b) => a.rect.y - b.rect.y)

  if (texts.length === 0) return out

  const pinned = out.filter((l) => l.kind === 'cta' || l.kind === 'logo')
  const x0 = Math.min(...texts.map((t) => t.rect.x))
  const x1 = Math.max(...texts.map((t) => t.rect.x + t.rect.w))
  const firstY = texts[0]!.rect.y
  let ceiling = live.y
  let floor = live.y + live.h

  for (const p of pinned) {
    if (p.rect.x >= x1 || p.rect.x + p.rect.w <= x0) continue

    if (p.rect.y <= firstY) ceiling = Math.max(ceiling, p.rect.y + p.rect.h + GAP)
    else floor = Math.min(floor, p.rect.y - GAP)
  }

  // The product (with its air) limits the column too, when the column sits over it sideways: the column keeps to the free side of it,
  // vertically, and shrinks to fit there rather than cover it.
  if (!(product.x >= x1 || product.x + product.w <= x0)) {
    const columnMid = firstY + texts[0]!.rect.h / 2
    const aboveColumn = product.y + product.h / 2 <= columnMid
    const nextCeiling = aboveColumn ? Math.max(ceiling, product.y + product.h + GAP) : ceiling
    const nextFloor = aboveColumn ? floor : Math.min(floor, product.y - GAP)

    // Only when a readable column still fits on the free side. A picture that is nearly all product leaves no such side, and then
    // the live area and the pinned layers win: the text stays legible and inside the margins rather than being squeezed to nothing.
    const natural = texts.reduce((sum, t) => sum + t.rect.h, 0) + GAP * (texts.length - 1)

    if (nextFloor - nextCeiling >= natural * 0.55) {
      ceiling = nextCeiling
      floor = nextFloor
    }
  }

  const gaps = GAP * (texts.length - 1)
  const heights = texts.reduce((s, t) => s + t.rect.h, 0)
  const room = Math.max(8 * texts.length, floor - ceiling - gaps)
  const k = heights > room ? room / heights : 1
  const sizes = texts.map((t) => Math.max(8, Math.floor(t.rect.h * k)))
  const total = sizes.reduce((s, h) => s + h, 0) + gaps
  let y = clamp(firstY, ceiling, floor - total)

  texts.forEach((t, i) => {
    t.rect.y = y
    t.rect.h = sizes[i]!
    y += sizes[i]! + GAP
  })

  return out
}
