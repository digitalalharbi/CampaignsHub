import { protectedBands } from '../direction/safeZone'
import { clamp01 } from './easing'
import { recipeFor } from './recipes'
import type { Scene, SceneLayer } from './scene'

/**
 * CONTENT-STUDIO-001 batch 5 — drawing a frame of a scene. It talks to a minimal 2D-context interface, so the same function draws on a
 * canvas in the page, on an OffscreenCanvas in a Worker for the export, and on a recording stub in a test.
 */
export interface Ctx2D {
  save(): void
  restore(): void
  translate(x: number, y: number): void
  scale(x: number, y: number): void
  beginPath(): void
  rect(x: number, y: number, w: number, h: number): void
  clip(): void
  fillRect(x: number, y: number, w: number, h: number): void
  fillText(text: string, x: number, y: number): void
  globalAlpha: number
  fillStyle: string
  font: string
  textAlign: 'left' | 'right' | 'center'
  textBaseline: 'alphabetic' | 'top' | 'middle'
  direction: 'rtl' | 'ltr'
}

export interface FrameOptions {
  /** Draws the protected bands over everything; the «show margins» switch. */
  showMargins?: boolean
  /** Draws the picture itself; the previewer and the exporter pass their own (an image, a video frame). */
  drawBackground?: (ctx: Ctx2D, progress: number) => void
  displayFont: string
  textFont: string
}

const progressOf = (layer: SceneLayer, tMs: number, still: boolean): number => (still ? 1 : clamp01((tMs - layer.enterAt) / layer.enterMs))

export function drawFrame(ctx: Ctx2D, scene: Scene, tMs: number, options: FrameOptions): void {
  const still = scene.durationMs === 0
  const clipProgress = still ? 0 : clamp01(tMs / scene.durationMs)

  options.drawBackground?.(ctx, clipProgress)

  for (const layer of scene.layers) {
    const p = progressOf(layer, tMs, still)

    // Not yet entered: nothing is drawn, so a layer that has not appeared cannot show in a frame.
    if (!still && tMs < layer.enterAt) continue

    const tr = recipeFor(layer.recipe)(p, { direction: scene.direction, rect: layer.rect })

    ctx.save()
    ctx.globalAlpha = tr.opacity
    ctx.translate(layer.rect.x + layer.rect.w / 2 + tr.translateX, layer.rect.y + layer.rect.h / 2 + tr.translateY)
    ctx.scale(tr.scaleX, tr.scaleY)

    // A reveal shows the first `reveal` of the width from the side the language starts on.
    if (tr.reveal < 1) {
      const shown = layer.rect.w * tr.reveal
      const x = scene.direction === 'rtl' ? layer.rect.w / 2 - shown : -layer.rect.w / 2
      ctx.beginPath()
      ctx.rect(x, -layer.rect.h / 2, shown, layer.rect.h)
      ctx.clip()
    }

    if (layer.fill !== null) {
      ctx.fillStyle = layer.fill
      ctx.fillRect(-layer.rect.w / 2, -layer.rect.h / 2, layer.rect.w, layer.rect.h)
    }

    if (layer.lines.length > 0) {
      ctx.fillStyle = layer.color
      ctx.font = `${layer.fontPx}px ${layer.font === 'display' ? options.displayFont : options.textFont}`
      ctx.direction = scene.direction
      ctx.textAlign = 'center'
      ctx.textBaseline = 'middle'

      const lineHeight = layer.fontPx * 1.25
      const top = -((layer.lines.length - 1) * lineHeight) / 2

      layer.lines.forEach((line, i) => ctx.fillText(line, 0, top + i * lineHeight))
    }

    ctx.restore()
  }

  if (options.showMargins) {
    ctx.save()
    ctx.globalAlpha = 0.35
    ctx.fillStyle = '#ff2d55'

    for (const b of protectedBands(scene.format)) ctx.fillRect(b.x, b.y, b.w, b.h)

    ctx.restore()
  }
}
