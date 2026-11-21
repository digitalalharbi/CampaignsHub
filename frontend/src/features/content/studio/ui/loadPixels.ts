import type { Pixels } from '../analysis/pixels'

/**
 * CONTENT-STUDIO-001 batch 7B — a File to pixels, in the browser. The analysis reads a small copy; the previews draw the full bitmap,
 * so nothing here is sent anywhere and no picture leaves the page.
 */
export interface LoadedPicture {
  bitmap: ImageBitmap
  pixels: Pixels
}

/** Longest edge of the copy the analysis reads; the analysis itself downsizes further. */
const ANALYSIS_EDGE = 512

export async function loadPixels(file: Blob): Promise<LoadedPicture> {
  const bitmap = await createImageBitmap(file)
  const scale = Math.min(1, ANALYSIS_EDGE / Math.max(bitmap.width, bitmap.height))
  const w = Math.max(1, Math.round(bitmap.width * scale))
  const h = Math.max(1, Math.round(bitmap.height * scale))
  const canvas = document.createElement('canvas')

  canvas.width = w
  canvas.height = h

  const ctx = canvas.getContext('2d', { willReadFrequently: true })

  if (!ctx) throw new Error('canvas_unavailable')

  ctx.drawImage(bitmap, 0, 0, w, h)

  const data = ctx.getImageData(0, 0, w, h)

  return { bitmap, pixels: { width: w, height: h, data: data.data } }
}

/** A text measurer backed by a real canvas, so Arabic is measured at its real advance. */
export function canvasMeasure(fontFamily: string): (text: string, px: number) => number {
  const ctx = document.createElement('canvas').getContext('2d')

  return (text, px) => {
    if (!ctx) return text.length * px * 0.5

    ctx.font = `${px}px ${fontFamily}`

    return ctx.measureText(text).width
  }
}

/** Draws `bitmap` to fill the 1080×1920 canvas without stretching it (cover), optionally zoomed for the slow Ken Burns move. */
export function drawCover(ctx: CanvasRenderingContext2D, bitmap: ImageBitmap, width: number, height: number, zoom = 1): void {
  const s = Math.max(width / bitmap.width, height / bitmap.height) * zoom
  const w = bitmap.width * s
  const h = bitmap.height * s

  ctx.drawImage(bitmap, (width - w) / 2, (height - h) / 2, w, h)
}
