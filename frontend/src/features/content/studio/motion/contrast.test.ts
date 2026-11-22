import { describe, expect, it } from 'vitest'
import type { Grid } from '../analysis/grid'
import { MIN_CONTRAST, overlayAt, whiteTextContrast } from './contrast'

const grid = (luma: number): Grid => ({
  cols: 12, rows: 20,
  cells: Array.from({ length: 240 }, (_, i) => ({ col: i % 12, row: Math.floor(i / 12), luma, busy: 0, contrast: 0, saturation: 0, r: luma, g: luma, b: luma })),
} as unknown as Grid)
const canvas = { width: 1080, height: 1920 }
const upper = { x: 100, y: 300, w: 880, h: 200 }
const lower = { x: 100, y: 1400, w: 880, h: 120 }

describe('CONTENT-LIBRARY-SNAP-001 — contrast gate', () => {
  it('white text on a bright picture fails, on a dark one passes', () => {
    expect(whiteTextContrast('glass', grid(240), upper, canvas, false)).toBeLessThan(MIN_CONTRAST)
    expect(whiteTextContrast('glass', grid(40), upper, canvas, false)).toBeGreaterThan(MIN_CONTRAST)
  })

  it('the scrims and the glass card darken what they sit on', () => {
    expect(overlayAt('marker', 1800, 1920, false)).toBe(0)
    expect(overlayAt('glass', 1800, 1920, false)).toBeGreaterThan(0.4)
    expect(overlayAt('bold', 100, 1920, false)).toBeGreaterThan(0.3)
    expect(whiteTextContrast('glass', grid(160), lower, canvas, true)).toBeGreaterThan(whiteTextContrast('glass', grid(160), lower, canvas, false))
  })
})
