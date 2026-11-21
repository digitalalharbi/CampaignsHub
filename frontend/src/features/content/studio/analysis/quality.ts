import { downscale, edgePlane, lumaPlane, type Pixels } from './pixels'

/**
 * Quality checks that WARN and suggest — they never block a picture. A weak image is still the agency's image; the studio says what it
 * sees and offers a fix, and the person decides.
 */
export type QualityWarning = 'low_resolution' | 'blurry' | 'underexposed' | 'overexposed' | 'clipped'

export interface Quality {
  width: number
  height: number
  /** Variance of the Laplacian of luma on a ≤512px copy; low means soft. Compared across images at the same scale. */
  sharpness: number
  meanLuma: number
  /** Share of pixels at the extremes, 0–1. */
  clippedShadows: number
  clippedHighlights: number
  warnings: QualityWarning[]
  /** True when the only sensible offer is «improve the light». */
  suggestLightFix: boolean
}

/** A 1080×1920 output wants at least this much picture before it has to invent any. */
export const MIN_LONG_EDGE = 1280
export const MIN_SHORT_EDGE = 720
export const BLUR_VARIANCE_BELOW = 60

export function laplacianVariance(src: Pixels): number {
  const p = downscale(src, 512)
  const plane = lumaPlane(p)
  const { width, height } = p
  let sum = 0
  let sum2 = 0
  let n = 0

  for (let y = 1; y < height - 1; y++) {
    for (let x = 1; x < width - 1; x++) {
      const i = y * width + x
      const v = plane[i - 1]! + plane[i + 1]! + plane[i - width]! + plane[i + width]! - 4 * plane[i]!
      sum += v
      sum2 += v * v
      n++
    }
  }

  if (n === 0) return 0

  const mean = sum / n

  return sum2 / n - mean * mean
}

export function assessQuality(src: Pixels): Quality {
  const plane = lumaPlane(src)
  let sum = 0
  let dark = 0
  let bright = 0

  for (let i = 0; i < plane.length; i++) {
    const l = plane[i]!
    sum += l
    if (l < 10) dark++
    if (l > 245) bright++
  }

  const meanLuma = sum / plane.length
  const clippedShadows = dark / plane.length
  const clippedHighlights = bright / plane.length
  const sharpness = laplacianVariance(src)
  const warnings: QualityWarning[] = []
  const long = Math.max(src.width, src.height)
  const short = Math.min(src.width, src.height)

  if (long < MIN_LONG_EDGE || short < MIN_SHORT_EDGE) warnings.push('low_resolution')
  if (sharpness < BLUR_VARIANCE_BELOW) warnings.push('blurry')
  if (meanLuma < 50) warnings.push('underexposed')
  if (meanLuma > 205) warnings.push('overexposed')
  if (clippedShadows > 0.35 || clippedHighlights > 0.35) warnings.push('clipped')

  return {
    width: src.width,
    height: src.height,
    sharpness,
    meanLuma,
    clippedShadows,
    clippedHighlights,
    warnings,
    suggestLightFix: warnings.some((w) => w === 'underexposed' || w === 'overexposed' || w === 'clipped'),
  }
}

/** Kept for the placement engine's «is this picture busy» reading. */
export function meanEdge(src: Pixels): number {
  const p = downscale(src, 192)
  const e = edgePlane(lumaPlane(p), p.width, p.height)
  let s = 0

  for (let i = 0; i < e.length; i++) s += e[i]!

  return e.length === 0 ? 0 : s / e.length
}
