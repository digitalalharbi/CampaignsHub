import { edgePlane, lumaPlane, saturation, type Pixels, type Rect } from './pixels'

/**
 * The picture on a 12×20 grid: for each cell its mean brightness, how busy it is (edge density), its contrast and its colour
 * saturation. The grid is the one thing the placement engine reads — where the product is, where the picture is calm, and what colour
 * of text a calm place will hold.
 */
export const GRID_COLS = 12
export const GRID_ROWS = 20

export interface Cell {
  col: number
  row: number
  luma: number
  /** Mean Sobel magnitude, 0–~1000. */
  busy: number
  /** Standard deviation of luma inside the cell. */
  contrast: number
  saturation: number
}

export interface Grid {
  cols: number
  rows: number
  cells: Cell[]
}

export function buildGrid(src: Pixels, cols = GRID_COLS, rows = GRID_ROWS): Grid {
  const plane = lumaPlane(src)
  const edges = edgePlane(plane, src.width, src.height)
  const cells: Cell[] = []

  for (let row = 0; row < rows; row++) {
    const y0 = Math.floor((row * src.height) / rows)
    const y1 = Math.max(y0 + 1, Math.floor(((row + 1) * src.height) / rows))

    for (let col = 0; col < cols; col++) {
      const x0 = Math.floor((col * src.width) / cols)
      const x1 = Math.max(x0 + 1, Math.floor(((col + 1) * src.width) / cols))
      let sumL = 0
      let sumL2 = 0
      let sumE = 0
      let sumS = 0
      let n = 0

      for (let y = y0; y < y1; y++) {
        for (let x = x0; x < x1; x++) {
          const i = y * src.width + x
          const l = plane[i]!
          sumL += l
          sumL2 += l * l
          sumE += edges[i]!
          sumS += saturation(src.data[i * 4]!, src.data[i * 4 + 1]!, src.data[i * 4 + 2]!)
          n++
        }
      }

      const mean = sumL / n

      cells.push({ col, row, luma: mean, busy: sumE / n, contrast: Math.sqrt(Math.max(0, sumL2 / n - mean * mean)), saturation: sumS / n })
    }
  }

  return { cols, rows, cells }
}

export const cellAt = (grid: Grid, col: number, row: number): Cell => grid.cells[row * grid.cols + col]!

/** The grid cells that sit fully inside a live-area rectangle given in canvas pixels. */
export function cellsInside(grid: Grid, canvas: { width: number; height: number }, live: Rect): Cell[] {
  const cw = canvas.width / grid.cols
  const ch = canvas.height / grid.rows

  return grid.cells.filter((c) => c.col * cw >= live.x - 1e-6 && (c.col + 1) * cw <= live.x + live.w + 1e-6 && c.row * ch >= live.y - 1e-6 && (c.row + 1) * ch <= live.y + live.h + 1e-6)
}
