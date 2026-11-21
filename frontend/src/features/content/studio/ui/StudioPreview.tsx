import { useEffect, useRef } from 'react'
import { drawFrame, type Ctx2D } from '../motion/render'
import type { Scene } from '../motion/scene'
import type { DesignTokens } from '../tokens/tokens'
import { drawCover } from './loadPixels'

/**
 * CONTENT-STUDIO-001 batch 7B — one live preview: the scene drawn on a canvas, nothing encoded. A video loops only while it is on
 * screen (so seven previews do not run seven animations at once), an image is drawn once. Everything is drawn by `drawFrame`, the same
 * function the exporter will call, so what is seen is what is exported.
 */
export function StudioPreview({ scene, bitmap, tokens, showMargins, live }: { scene: Scene; bitmap: ImageBitmap | null; tokens: DesignTokens; showMargins: boolean; live: boolean }) {
  const ref = useRef<HTMLCanvasElement | null>(null)
  const visible = useRef(true)

  useEffect(() => {
    const canvas = ref.current
    const ctx = canvas?.getContext('2d')

    if (!canvas || !ctx) return

    const draw = (tMs: number) => {
      ctx.clearRect(0, 0, scene.width, scene.height)
      ctx.fillStyle = tokens.colors.surface
      ctx.fillRect(0, 0, scene.width, scene.height)
      drawFrame(ctx as unknown as Ctx2D, scene, tMs, {
        showMargins,
        displayFont: `"${tokens.fontPair.display}", sans-serif`,
        textFont: `"${tokens.fontPair.text}", sans-serif`,
        drawBackground: (_c, progress) => {
          if (bitmap) drawCover(ctx, bitmap, scene.width, scene.height, 1 + 0.08 * progress)
        },
      })
    }

    if (!live || scene.durationMs === 0) {
      draw(scene.durationMs === 0 ? 0 : scene.durationMs)

      return
    }

    const observer = new IntersectionObserver(([entry]) => { visible.current = entry?.isIntersecting ?? true })

    observer.observe(canvas)

    let frame = 0
    let start: number | null = null
    const tick = (now: number) => {
      start ??= now

      if (visible.current) draw((now - start) % scene.durationMs)

      frame = requestAnimationFrame(tick)
    }

    frame = requestAnimationFrame(tick)

    return () => {
      cancelAnimationFrame(frame)
      observer.disconnect()
    }
  }, [scene, bitmap, tokens, showMargins, live])

  return <canvas ref={ref} width={scene.width} height={scene.height} className="h-auto w-full rounded-xl bg-surface-secondary" data-testid="studio-preview" />
}
