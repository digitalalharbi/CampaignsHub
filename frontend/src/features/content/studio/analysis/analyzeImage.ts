import { buildGrid, type Grid } from './grid'
import { accentCandidate, palette, type Swatch } from './palette'
import { calmestBlock, focusBox, textTreatment, type Block, type Canvas } from './placement'
import { downscale, type Pixels, type Rect } from './pixels'
import { assessQuality, meanEdge, type Quality } from './quality'

/**
 * CONTENT-STUDIO-001 batch 2 — the whole reading of one picture. Everything the layout engine will need, derived from the pixels
 * alone: where the product is, how the picture is classed, its palette, the calm place for a block of text and what colour that
 * place wants, and whether the image is fit to be used.
 */
export interface ImageAnalysis {
  grid: Grid
  product: Rect
  swatches: Swatch[]
  accent: Swatch | null
  classes: { tone: 'light' | 'dark'; temperature: 'warm' | 'cool' | 'neutral'; complexity: 'busy' | 'simple' }
  quality: Quality
}

/** Mean Sobel magnitude on a ≤192px copy above which a picture reads as crowded. */
export const BUSY_ABOVE = 90

export function analyzeImage(src: Pixels): ImageAnalysis {
  const small = downscale(src, 192)
  const grid = buildGrid(small)
  const swatches = palette(small)
  const meanLuma = grid.cells.reduce((s, c) => s + c.luma, 0) / grid.cells.length
  let r = 0
  let b = 0
  const n = small.width * small.height

  for (let i = 0; i < n; i++) {
    r += small.data[i * 4]!
    b += small.data[i * 4 + 2]!
  }

  const warm = r / n - b / n

  return {
    grid,
    product: focusBox(grid),
    swatches,
    accent: accentCandidate(swatches),
    classes: {
      tone: meanLuma < 128 ? 'dark' : 'light',
      temperature: warm > 8 ? 'warm' : warm < -8 ? 'cool' : 'neutral',
      complexity: meanEdge(src) > BUSY_ABOVE ? 'busy' : 'simple',
    },
    quality: assessQuality(src),
  }
}

export interface TextPlacement {
  block: Block
  tone: 'light' | 'dark'
  scrim: boolean
}

/** The place for a block of copy `cols`×`rows` cells big, and how to colour it — inside `live` (canvas pixels) and clear of the product. */
export function placeText(analysis: ImageAnalysis, canvas: Canvas, live: Rect, cols: number, rows: number): TextPlacement | null {
  const block = calmestBlock(analysis.grid, canvas, live, cols, rows, analysis.product)

  return block === null ? null : { block, ...textTreatment(block) }
}
