import { exportVideo } from '../export/exportVideo'
import { planExport } from '../export/exportPlan'
import { createMp4Pipeline } from '../export/mp4Adapter'
import { H, W } from './constants'
import type { Genome } from './genome'
import { DEFAULT_DURATION_MS, paint, type PaintOptions } from './paint'
import type { El } from './solve'

/** CREATIVE-ENGINE-001 — the saved file, drawn by the same `paint` as the preview: a PNG still, or an H.264 MP4 through WebCodecs. */
function canvas() {
  const c = document.createElement('canvas')
  c.width = W
  c.height = H
  const ctx = c.getContext('2d')
  if (!ctx) throw new Error('canvas_unavailable')

  return { c, ctx }
}

export async function renderEngineImage(els: El[], g: Genome, o: PaintOptions, locale: 'ar' | 'en'): Promise<Blob> {
  const { c, ctx } = canvas()
  paint(ctx, els, g, o, null, DEFAULT_DURATION_MS, locale)

  return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('encode_failed'))), 'image/png'))
}

export async function renderEngineVideo(els: El[], g: Genome, o: PaintOptions, locale: 'ar' | 'en', durationMs = DEFAULT_DURATION_MS): Promise<Blob> {
  const { c, ctx } = canvas()
  const plan = planExport({ width: W, height: H, durationMs, withAudio: false })
  const { encoder, muxer } = createMp4Pipeline(plan, (t) => { paint(ctx, els, g, o, t, durationMs, locale); return c })
  const bytes = await exportVideo({ plan, encoder, muxer })

  return new Blob([new Uint8Array(bytes)], { type: 'video/mp4' })
}

/** The nearest system family, for the storage API (which knows the seven families); the full genome travels in the tokens. */
export const FAMILY_FOR: Record<string, string> = {
  'side-stack': 'stacked-reveal', 'bold-top-hook': 'bold-hook', 'offer-badge': 'offer-card', 'number-hero': 'offer-card',
  'surface-card': 'split', 'editorial-frame': 'editorial-frame', 'corner-brackets': 'editorial-frame', 'minimal-center': 'minimal',
  'word-cascade': 'stacked-reveal', 'ribbon-banner': 'bold-hook', 'diagonal-band': 'bold-hook', 'product-spotlight': 'detail-cuts',
}
