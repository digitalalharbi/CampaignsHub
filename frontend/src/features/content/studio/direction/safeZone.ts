/**
 * CONTENT-STUDIO-001 — the safe margins, ONE definition for the previewer and the exporter.
 *
 * A 1080×1920 output keeps its top 10% (0–192) and bottom 20% (1536–1920) for the background picture only, and 6% on each side
 * (0–65, 1015–1080); every text, logo, bar, price and call-to-action sits in the live area between them. The platforms draw their
 * own interface over those bands (name, caption, buttons), so a layer there is covered in the feed — which is why touching one is a
 * hard failure, not a warning.
 *
 * Pure numbers, no DOM: the same function runs in a Web Worker, in the live preview and in the tests.
 */

export type FormatKey = '9x16' | '4x5' | '1x1' | '1.91x1'

export const FORMATS: Readonly<Record<FormatKey, { width: number; height: number }>> = {
  '9x16': { width: 1080, height: 1920 },
  '4x5': { width: 1080, height: 1350 },
  '1x1': { width: 1080, height: 1080 },
  '1.91x1': { width: 1200, height: 628 },
}

export interface Rect {
  x: number
  y: number
  w: number
  h: number
}

/** `background` is the one kind allowed to cover the whole canvas, margins included. */
export type LayerKind = 'background' | 'text' | 'logo' | 'cta' | 'shape' | 'price' | 'bar'

export interface Layer {
  id: string
  kind: LayerKind
  rect: Rect
}

export interface MarginViolation {
  layerId: string
  kind: LayerKind
  /** Which edges the layer crosses. */
  edges: ReadonlyArray<'top' | 'bottom' | 'left' | 'right'>
}

const TOP_9X16 = 0.1
const BOTTOM_9X16 = 0.2
const SIDE = 0.06

/** The area every non-background layer must sit inside, in canvas pixels. */
export function liveArea(format: FormatKey): Rect {
  const { width, height } = FORMATS[format]
  const side = Math.round(width * SIDE)

  if (format === '9x16') {
    const top = Math.round(height * TOP_9X16)
    const bottom = Math.round(height * BOTTOM_9X16)

    return { x: side, y: top, w: width - side * 2, h: height - top - bottom }
  }

  // The optional ratios keep an inner 6% on every side.
  const inner = Math.round(Math.min(width, height) * SIDE)

  return { x: inner, y: inner, w: width - inner * 2, h: height - inner * 2 }
}

/** The protected bands (what the «show margins» overlay draws), as rectangles. */
export function protectedBands(format: FormatKey): Rect[] {
  const { width, height } = FORMATS[format]
  const live = liveArea(format)

  return [
    { x: 0, y: 0, w: width, h: live.y },
    { x: 0, y: live.y + live.h, w: width, h: height - live.y - live.h },
    { x: 0, y: live.y, w: live.x, h: live.h },
    { x: live.x + live.w, y: live.y, w: width - live.x - live.w, h: live.h },
  ]
}

export function insideLiveArea(rect: Rect, format: FormatKey): boolean {
  return crossedEdges(rect, format).length === 0
}

function crossedEdges(rect: Rect, format: FormatKey): MarginViolation['edges'] {
  const live = liveArea(format)
  const edges: Array<'top' | 'bottom' | 'left' | 'right'> = []

  if (rect.y < live.y) edges.push('top')
  if (rect.y + rect.h > live.y + live.h) edges.push('bottom')
  if (rect.x < live.x) edges.push('left')
  if (rect.x + rect.w > live.x + live.w) edges.push('right')

  return edges
}

/** Every layer that touches a protected band. A background is exempt; an empty list is the only passing answer. */
export function marginViolations(layers: ReadonlyArray<Layer>, format: FormatKey): MarginViolation[] {
  return layers
    .filter((layer) => layer.kind !== 'background')
    .flatMap((layer) => {
      const edges = crossedEdges(layer.rect, format)

      return edges.length === 0 ? [] : [{ layerId: layer.id, kind: layer.kind, edges }]
    })
}
