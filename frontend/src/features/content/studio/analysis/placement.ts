import { cellsInside, type Cell, type Grid } from './grid'
import type { Rect } from './pixels'

/**
 * Where the product is, where the picture is calm, and what colour of text a calm place holds.
 *
 * The product box and the calm zones never leave the live area: a block of text is only ever offered a place that is outside the
 * protected bands AND away from the product. (The live area is passed in canvas pixels by the caller — the studio's safe margins —
 * so this module stays free of any one format.)
 */

export interface Canvas {
  width: number
  height: number
}

/**
 * Normalised (0–1) box of the product. A product is what differs from its surroundings, so each cell is scored by how far its colour is
 * from the picture's border, plus how busy and saturated it is: a flat-coloured bottle (no edges inside, a strong colour against its
 * backdrop) is found as well as a detailed one. Edge energy alone missed exactly that case.
 */
export function focusBox(grid: Grid): Rect {
  const border = grid.cells.filter((c) => c.col === 0 || c.row === 0 || c.col === grid.cols - 1 || c.row === grid.rows - 1)
  const bg = { r: mean(border.map((c) => c.r)), g: mean(border.map((c) => c.g)), b: mean(border.map((c) => c.b)) }
  const distance = (c: Cell) => Math.sqrt((c.r - bg.r) ** 2 + (c.g - bg.g) ** 2 + (c.b - bg.b) ** 2)
  const score = (c: Cell) => distance(c) * 2 + c.busy * (1 + 2 * c.saturation) + c.contrast
  const max = Math.max(...grid.cells.map(score))

  if (max <= 0) return { x: 0.25, y: 0.25, w: 0.5, h: 0.5 }

  const hot = grid.cells.filter((c) => score(c) >= max * 0.4)
  const minC = Math.min(...hot.map((c) => c.col))
  const maxC = Math.max(...hot.map((c) => c.col))
  const minR = Math.min(...hot.map((c) => c.row))
  const maxR = Math.max(...hot.map((c) => c.row))

  return { x: minC / grid.cols, y: minR / grid.rows, w: (maxC - minC + 1) / grid.cols, h: (maxR - minR + 1) / grid.rows }
}

const mean = (xs: number[]): number => (xs.length === 0 ? 0 : xs.reduce((s, x) => s + x, 0) / xs.length)

export interface Block {
  col: number
  row: number
  /** Size in grid cells. */
  cols: number
  rows: number
  /** Mean busyness of the cells it covers; lower is calmer. */
  busy: number
  /** Mean brightness of the cells it covers. */
  luma: number
  contrast: number
  overlapsProduct: boolean
}

/** Half a grid cell of air around the product: text must clear it, not merely avoid touching its bounding box. */
export const PRODUCT_AIR = { x: 0.5, y: 0.5 }

const overlaps = (c: Cell, grid: Grid, box: Rect): boolean => {
  const x0 = c.col / grid.cols
  const x1 = (c.col + 1) / grid.cols
  const y0 = c.row / grid.rows
  const y1 = (c.row + 1) / grid.rows
  const ax = PRODUCT_AIR.x / grid.cols
  const ay = PRODUCT_AIR.y / grid.rows

  return x0 < box.x + box.w + ax && x1 > box.x - ax && y0 < box.y + box.h + ay && y1 > box.y - ay
}

/**
 * The calmest `cols`×`rows` block whose every cell is inside the live area. A block clear of the product wins over a calmer one that
 * covers it; when nothing is clear, the calmest block is returned with `overlapsProduct: true` so the caller can shrink the text
 * instead of hiding the product.
 */
export function calmestBlock(grid: Grid, canvas: Canvas, live: Rect, cols: number, rows: number, product: Rect = focusBox(grid)): Block | null {
  const inside = new Set(cellsInside(grid, canvas, live).map((c) => c.row * grid.cols + c.col))
  let best: Block | null = null

  for (let row = 0; row + rows <= grid.rows; row++) {
    for (let col = 0; col + cols <= grid.cols; col++) {
      const cells: Cell[] = []
      let allInside = true

      for (let r = row; r < row + rows && allInside; r++) {
        for (let c = col; c < col + cols; c++) {
          if (!inside.has(r * grid.cols + c)) {
            allInside = false
            break
          }
          cells.push(grid.cells[r * grid.cols + c]!)
        }
      }

      if (!allInside || cells.length === 0) continue

      const n = cells.length
      const candidate: Block = {
        col,
        row,
        cols,
        rows,
        busy: cells.reduce((s, c) => s + c.busy, 0) / n,
        luma: cells.reduce((s, c) => s + c.luma, 0) / n,
        contrast: cells.reduce((s, c) => s + c.contrast, 0) / n,
        overlapsProduct: cells.some((c) => overlaps(c, grid, product)),
      }

      if (best === null || better(candidate, best)) best = candidate
    }
  }

  return best
}

function better(a: Block, b: Block): boolean {
  if (a.overlapsProduct !== b.overlapsProduct) return !a.overlapsProduct

  return a.busy < b.busy
}

export type TextTone = 'light' | 'dark'

/** Light text on a dark place, dark text on a light one; a scrim under the text only when the place is neither. */
export function textTreatment(block: Pick<Block, 'luma' | 'contrast'>): { tone: TextTone; scrim: boolean } {
  const tone: TextTone = block.luma < 128 ? 'light' : 'dark'
  const midBand = Math.abs(block.luma - 128) < 35
  const noisy = block.contrast > 45

  return { tone, scrim: midBand || noisy }
}
