import type { Grid } from '../analysis/grid'
import type { Rect } from '../direction/safeZone'
import type { Look } from './look'

/**
 * CONTENT-LIBRARY-SNAP-001 — the contrast gate. White text drawn straight on a bright picture is the defect the Owner saw («التباين
 * ضعيف على الخلفيات الفاتحة»). The picture under each text block is read from the analysis grid, darkened by the scrim the look lays
 * there, and compared with white by the WCAG ratio. Below 4.5:1 the output switches to the marker look, whose dark text on its own
 * highlight does not depend on the picture at all.
 */
export const MIN_CONTRAST = 4.5

const linear = (v255: number): number => {
  const c = v255 / 255

  return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

/** The mean relative luminance of the picture under a rect of the canvas. */
export function luminanceUnder(grid: Grid, rect: Rect, canvas: { width: number; height: number }): number {
  const cw = canvas.width / grid.cols
  const ch = canvas.height / grid.rows
  let sum = 0
  let n = 0
  for (const cell of grid.cells) {
    const x = cell.col * cw
    const y = cell.row * ch
    if (x + cw <= rect.x || x >= rect.x + rect.w || y + ch <= rect.y || y >= rect.y + rect.h) continue
    sum += linear(cell.luma)
    n += 1
  }

  return n === 0 ? 0 : sum / n
}

/** How much black the look lays over the picture at a height (the scrims of `drawScrim`, and the glass card). */
export function overlayAt(look: Look, y: number, height: number, onCard: boolean): number {
  if (look === 'marker') return 0
  let a = 0
  if (y > height * 0.5) a = Math.max(a, 0.6 * ((y - height * 0.5) / (height * 0.5)))
  if (look === 'bold' && y < height * 0.35) a = Math.max(a, 0.55 * (1 - y / (height * 0.35)))
  if (look === 'glass' && onCard) a = 1 - (1 - a) * (1 - 0.42)

  return a
}

/** White text against the picture as the look darkens it. */
export function whiteTextContrast(look: Look, grid: Grid, rect: Rect, canvas: { width: number; height: number }, onCard: boolean): number {
  const bg = luminanceUnder(grid, rect, canvas) * (1 - overlayAt(look, rect.y + rect.h / 2, canvas.height, onCard))

  return 1.05 / (bg + 0.05)
}
