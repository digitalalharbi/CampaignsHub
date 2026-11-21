import { drawFrame, type Ctx2D } from '../motion/render'
import type { Scene } from '../motion/scene'
import type { DesignTokens } from '../tokens/tokens'
import { drawCover } from '../ui/loadPixels'
import { exportVideo } from './exportVideo'
import { planExport } from './exportPlan'
import { createMp4Pipeline } from './mp4Adapter'

/**
 * CONTENT-LIBRARY-SNAP-001 — turns one studio output into the file that is saved. It draws with `drawFrame`, the same function the live
 * preview uses, so the saved file is what was on screen. Nothing here talks to a server.
 */
function painter(scene: Scene, bitmap: ImageBitmap, tokens: DesignTokens) {
  const canvas = document.createElement('canvas')
  canvas.width = scene.width
  canvas.height = scene.height
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('canvas_unavailable')

  const paint = (tMs: number) => {
    ctx.clearRect(0, 0, scene.width, scene.height)
    ctx.fillStyle = tokens.colors.surface
    ctx.fillRect(0, 0, scene.width, scene.height)
    drawFrame(ctx as unknown as Ctx2D, scene, tMs, {
      showMargins: false,
      displayFont: `"${tokens.fontPair.display}", sans-serif`,
      textFont: `"${tokens.fontPair.text}", sans-serif`,
      drawBackground: (_c, progress) => drawCover(ctx, bitmap, scene.width, scene.height, 1 + 0.08 * progress),
    })

    return canvas
  }

  return { canvas, paint }
}

/** The still: the last frame (every layer fully in). */
export async function renderImage(scene: Scene, bitmap: ImageBitmap, tokens: DesignTokens): Promise<Blob> {
  const { canvas, paint } = painter(scene, bitmap, tokens)
  paint(scene.durationMs)

  return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('encode_failed'))), 'image/png'))
}

/** True where this browser can encode an MP4 at all (WebCodecs). Where it cannot, the video is offered as a still instead. */
export function canExportVideo(): boolean {
  return typeof window !== 'undefined' && 'VideoEncoder' in window && 'VideoFrame' in window
}

export async function renderVideo(scene: Scene, bitmap: ImageBitmap, tokens: DesignTokens, onProgress?: (done: number, total: number) => void): Promise<Blob> {
  const plan = planExport({ width: scene.width, height: scene.height, durationMs: scene.durationMs, withAudio: false })
  const { paint } = painter(scene, bitmap, tokens)
  const { encoder, muxer } = createMp4Pipeline(plan, paint)
  const bytes = await exportVideo({ plan, encoder, muxer, onProgress })

  return new Blob([new Uint8Array(bytes)], { type: 'video/mp4' })
}
