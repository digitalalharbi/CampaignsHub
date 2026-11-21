import { describe, expect, it } from 'vitest'
import { analyzeImage, placeText } from './analyzeImage'
import { buildGrid, GRID_COLS, GRID_ROWS } from './grid'
import { palette } from './palette'
import { laplacianVariance, assessQuality } from './quality'
import { downscale, type Pixels } from './pixels'

/** Synthetic RGBA images, so every case is the same pixels on every machine. */
function image(width: number, height: number, paint: (x: number, y: number) => [number, number, number]): Pixels {
  const data = new Uint8ClampedArray(width * height * 4)

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const [r, g, b] = paint(x, y)
      const i = (y * width + x) * 4
      data[i] = r
      data[i + 1] = g
      data[i + 2] = b
      data[i + 3] = 255
    }
  }

  return { width, height, data }
}

const solid = (w: number, h: number, rgb: [number, number, number]) => image(w, h, () => rgb)
const checker = (w: number, h: number, cell: number) => image(w, h, (x, y) => (((x / cell) | 0) + ((y / cell) | 0)) % 2 === 0 ? [255, 255, 255] : [0, 0, 0])
/** A flat grey canvas with one saturated red block near the upper-left. */
const productOnGrey = (w = 540, h = 960) =>
  image(w, h, (x, y) => (x > w * 0.1 && x < w * 0.45 && y > h * 0.2 && y < h * 0.4 ? [220, 30, 30] : [120, 120, 120]))

const CANVAS = { width: 1080, height: 1920 }
const LIVE = { x: 65, y: 192, w: 950, h: 1344 }

describe('the grid', () => {
  it('is 12 columns by 20 rows and reads brightness per cell', () => {
    const g = buildGrid(solid(240, 400, [200, 200, 200]))

    expect(g.cols).toBe(GRID_COLS)
    expect(g.rows).toBe(GRID_ROWS)
    expect(g.cells).toHaveLength(240)
    expect(g.cells[0]!.luma).toBeCloseTo(200, 0)
    expect(g.cells[0]!.busy).toBe(0)
  })

  it('reads a busy picture as busier than a flat one', () => {
    const flat = buildGrid(solid(240, 400, [128, 128, 128]))
    const busy = buildGrid(checker(240, 400, 4))

    expect(busy.cells[100]!.busy).toBeGreaterThan(flat.cells[100]!.busy + 100)
  })
})

describe('the product box', () => {
  it('lands on the saturated block, not on the flat grey', () => {
    const a = analyzeImage(productOnGrey())

    // The block spans x 10–45%, y 20–40%; the box must overlap it and stay far from the bottom half.
    expect(a.product.x).toBeLessThan(0.45)
    expect(a.product.x + a.product.w).toBeGreaterThan(0.1)
    expect(a.product.y).toBeLessThan(0.45)
    expect(a.product.y + a.product.h).toBeLessThan(0.7)
  })
})

describe('the calm place for text', () => {
  it('sits inside the live area and clear of the product', () => {
    const a = analyzeImage(productOnGrey())
    const p = placeText(a, CANVAS, LIVE, 6, 3)

    expect(p).not.toBeNull()
    expect(p!.block.overlapsProduct).toBe(false)
    // Every cell of the block is inside the live area: rows 2..15, columns 1..10 of the 12×20 grid.
    expect(p!.block.row).toBeGreaterThanOrEqual(2)
    expect(p!.block.row + p!.block.rows).toBeLessThanOrEqual(16)
    expect(p!.block.col).toBeGreaterThanOrEqual(1)
    expect(p!.block.col + p!.block.cols).toBeLessThanOrEqual(11)
  })

  it('answers light text on a dark place and dark text on a light one', () => {
    const dark = placeText(analyzeImage(solid(540, 960, [20, 20, 30])), CANVAS, LIVE, 6, 3)
    const light = placeText(analyzeImage(solid(540, 960, [235, 235, 230])), CANVAS, LIVE, 6, 3)

    expect(dark!.tone).toBe('light')
    expect(light!.tone).toBe('dark')
    expect(dark!.scrim).toBe(false)
  })

  it('asks for a scrim on a mid-grey place where neither colour would read', () => {
    expect(placeText(analyzeImage(solid(540, 960, [128, 128, 128])), CANVAS, LIVE, 6, 3)!.scrim).toBe(true)
  })

  it('offers nothing when no block of that size fits the live area', () => {
    expect(placeText(analyzeImage(solid(540, 960, [128, 128, 128])), CANVAS, LIVE, 12, 20)).toBeNull()
  })
})

describe('the palette', () => {
  it('finds both colours of a two-colour picture, largest first, and always gives the same answer', () => {
    const img = image(300, 300, (x) => (x < 210 ? [200, 40, 40] : [30, 60, 200]))
    const a = palette(img, 2)
    const b = palette(img, 2)

    expect(a).toEqual(b)
    expect(a).toHaveLength(2)
    expect(a[0]!.share).toBeGreaterThan(0.65)
    expect(a[0]!.rgb[0]).toBeGreaterThan(150)
    expect(a[1]!.rgb[2]).toBeGreaterThan(150)
    expect(a[0]!.hex).toMatch(/^#[0-9a-f]{6}$/)
  })

  it('proposes the vivid colour as the accent, not the grey', () => {
    const accent = analyzeImage(productOnGrey()).accent

    expect(accent).not.toBeNull()
    expect(accent!.rgb[0]).toBeGreaterThan(accent!.rgb[1] + 40)
  })
})

describe('the classes', () => {
  it('tells light from dark, warm from cool, busy from simple', () => {
    expect(analyzeImage(solid(400, 400, [240, 240, 240])).classes.tone).toBe('light')
    expect(analyzeImage(solid(400, 400, [20, 20, 20])).classes.tone).toBe('dark')
    expect(analyzeImage(solid(400, 400, [220, 140, 80])).classes.temperature).toBe('warm')
    expect(analyzeImage(solid(400, 400, [80, 140, 220])).classes.temperature).toBe('cool')
    expect(analyzeImage(solid(400, 400, [128, 128, 128])).classes.temperature).toBe('neutral')
    expect(analyzeImage(solid(400, 400, [128, 128, 128])).classes.complexity).toBe('simple')
    expect(analyzeImage(checker(400, 400, 3)).classes.complexity).toBe('busy')
  })
})

describe('the quality check warns and never blocks', () => {
  it('a sharp large picture has no warnings', () => {
    const q = assessQuality(image(1440, 2560, (x, y) => (((x >> 3) + (y >> 3)) % 2 === 0 ? [200, 180, 150] : [60, 70, 90])))

    expect(q.warnings).toEqual([])
  })

  it('flags a small picture, a soft one, and a dark one', () => {
    expect(assessQuality(checker(400, 600, 8)).warnings).toContain('low_resolution')
    expect(assessQuality(solid(1440, 2560, [128, 128, 128])).warnings).toContain('blurry')

    const dark = assessQuality(solid(1440, 2560, [10, 10, 12]))

    expect(dark.warnings).toContain('underexposed')
    expect(dark.suggestLightFix).toBe(true)
  })

  it('a sharp picture measures sharper than the same picture blurred', () => {
    const sharp = checker(512, 512, 2)
    const blurred = downscale(downscale(sharp, 16), 512)

    expect(laplacianVariance(sharp)).toBeGreaterThan(laplacianVariance(blurred) * 5)
  })
})

function lcg(seed: number) {
  let s = seed >>> 0

  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0

    return s / 2 ** 32
  }
}

describe('property: any picture is read without failing, and a text place always stays inside the live area', () => {
  it('holds for 200 seeded random pictures (light, dark, busy, simple, small)', () => {
    const rnd = lcg(20261121)

    for (let i = 0; i < 200; i++) {
      const w = 48 + Math.floor(rnd() * 400)
      const h = 48 + Math.floor(rnd() * 600)
      const base: [number, number, number] = [Math.floor(rnd() * 256), Math.floor(rnd() * 256), Math.floor(rnd() * 256)]
      const noise = Math.floor(rnd() * 3) === 0 ? 120 : Math.floor(rnd() * 3) === 0 ? 30 : 0
      const img = image(w, h, () => [0, 1, 2].map((k) => Math.min(255, Math.max(0, base[k]! + (rnd() - 0.5) * noise))) as [number, number, number])

      const a = analyzeImage(img)
      const total = a.swatches.reduce((s, x) => s + x.share, 0)
      const p = placeText(a, CANVAS, LIVE, 5, 2)

      expect(total, `picture ${i}`).toBeCloseTo(1, 5)
      expect(p, `picture ${i}`).not.toBeNull()
      expect(p!.block.row, `picture ${i}`).toBeGreaterThanOrEqual(2)
      expect(p!.block.row + p!.block.rows, `picture ${i}`).toBeLessThanOrEqual(16)
      expect(p!.block.col, `picture ${i}`).toBeGreaterThanOrEqual(1)
      expect(p!.block.col + p!.block.cols, `picture ${i}`).toBeLessThanOrEqual(11)
      expect(['light', 'dark']).toContain(p!.tone)
    }
  })
})
