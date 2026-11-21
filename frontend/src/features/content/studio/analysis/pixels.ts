/**
 * CONTENT-STUDIO-001 batch 2 — image analysis, in the browser, for any image, at no platform cost.
 *
 * Everything here takes plain RGBA pixels (the shape `ImageData` has) and returns numbers, so it runs the same in a Web Worker,
 * in the live previewer and in Node tests. Nothing draws, nothing calls a server, nothing calls an AI provider.
 */

export interface Pixels {
  width: number
  height: number
  /** RGBA, row-major, 4 bytes per pixel. */
  data: Uint8ClampedArray | Uint8Array | number[]
}

export interface Rect {
  x: number
  y: number
  w: number
  h: number
}

export const luma = (r: number, g: number, b: number): number => 0.2126 * r + 0.7152 * g + 0.0722 * b

/** Box-average down to at most `maxSide` on the long edge — enough to read composition, cheap enough for a phone. */
export function downscale(src: Pixels, maxSide = 192): Pixels {
  const long = Math.max(src.width, src.height)

  if (long <= maxSide) return src

  const scale = maxSide / long
  const w = Math.max(1, Math.round(src.width * scale))
  const h = Math.max(1, Math.round(src.height * scale))
  const out = new Uint8ClampedArray(w * h * 4)

  for (let y = 0; y < h; y++) {
    const y0 = Math.floor((y * src.height) / h)
    const y1 = Math.max(y0 + 1, Math.floor(((y + 1) * src.height) / h))

    for (let x = 0; x < w; x++) {
      const x0 = Math.floor((x * src.width) / w)
      const x1 = Math.max(x0 + 1, Math.floor(((x + 1) * src.width) / w))
      let r = 0
      let g = 0
      let b = 0
      let n = 0

      for (let yy = y0; yy < y1; yy++) {
        for (let xx = x0; xx < x1; xx++) {
          const i = (yy * src.width + xx) * 4
          r += src.data[i]!
          g += src.data[i + 1]!
          b += src.data[i + 2]!
          n++
        }
      }

      const o = (y * w + x) * 4
      out[o] = r / n
      out[o + 1] = g / n
      out[o + 2] = b / n
      out[o + 3] = 255
    }
  }

  return { width: w, height: h, data: out }
}

/** Luma plane, 0–255. */
export function lumaPlane(src: Pixels): Float32Array {
  const out = new Float32Array(src.width * src.height)

  for (let i = 0; i < out.length; i++) out[i] = luma(src.data[i * 4]!, src.data[i * 4 + 1]!, src.data[i * 4 + 2]!)

  return out
}

/** Sobel gradient magnitude per pixel (border pixels are 0). The «busyness» of the picture, before any grouping. */
export function edgePlane(plane: Float32Array, width: number, height: number): Float32Array {
  const out = new Float32Array(width * height)

  for (let y = 1; y < height - 1; y++) {
    for (let x = 1; x < width - 1; x++) {
      const i = y * width + x
      const gx = -plane[i - width - 1]! - 2 * plane[i - 1]! - plane[i + width - 1]! + plane[i - width + 1]! + 2 * plane[i + 1]! + plane[i + width + 1]!
      const gy = -plane[i - width - 1]! - 2 * plane[i - width]! - plane[i - width + 1]! + plane[i + width - 1]! + 2 * plane[i + width]! + plane[i + width + 1]!
      out[i] = Math.sqrt(gx * gx + gy * gy)
    }
  }

  return out
}

export const saturation = (r: number, g: number, b: number): number => {
  const max = Math.max(r, g, b)
  const min = Math.min(r, g, b)

  return max === 0 ? 0 : (max - min) / max
}
