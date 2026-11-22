import { H, OVERSIZE, W } from './constants'
import type { FitPlan } from './fit'
import type { Genome } from './genome'
import type { El } from './solve'
import { DISPLAY_WEIGHT, TEXT_WEIGHT } from '../ui/fonts'

/**
 * CREATIVE-ENGINE-001 — drawing a candidate, still or at a moment of its film. Ported from the reference's `cam`, `grade`,
 * `apply_anim` and `video_plan`, on a real canvas in the browser.
 *
 * The film keeps the reference's structure: the hook from 0 to 3s, then the message, and the CTA held, still and readable, for the
 * last 2.5s under a darkened frame. The camera follows the genome (push, pull, pan, punch on the beat, a little hand, detail cuts),
 * cuts on the genome's BPM, and changes shot with its transition. Arabic moves by the line or by a mask, never letter by letter.
 */
type Ctx = CanvasRenderingContext2D | OffscreenCanvasRenderingContext2D

export interface PaintOptions {
  plan: FitPlan
  bitmap: CanvasImageSource & { width: number; height: number }
  focus: { x: number; y: number }
  /** Families as CSS font-family values, with the weights this pair draws at (a single-weight display face must not be faux-bolded). */
  fonts: { display: string; text: string; displayWeight?: number; textWeight?: number }
  /** «تأثيرات الفيديو»: a little more contrast and colour on the picture. */
  colorBoost?: boolean
  showMargins?: boolean
}

export const DEFAULT_DURATION_MS = 12_000
const HOOK_END = 3000
const CTA_HOLD = 2500

const clamp01 = (v: number) => Math.min(1, Math.max(0, v))
const easeOut = (t: number) => 1 - (1 - clamp01(t)) ** 3
const easeQuart = (t: number) => 1 - (1 - clamp01(t)) ** 4
const easeIO = (t: number) => -(Math.cos(Math.PI * clamp01(t)) - 1) / 2
const prog = (t: number, a: number, b: number) => (t <= a ? 0 : t >= b ? 1 : (t - a) / (b - a))

/** The camera at a moment: zoom and pan (in camera-canvas pixels), from the genome and the beat. */
function cameraAt(g: Genome, tMs: number, dur: number): { z: number; pan: [number, number]; shot: number } {
  const t = tMs / 1000
  const beat = 60 / g.bpm
  const ctaT = (dur - CTA_HOLD) / 1000
  const shot = t >= ctaT ? 3 : t >= HOOK_END / 1000 ? 1 + Math.floor((t - HOOK_END / 1000) / (beat * 4)) % 2 : 0
  const local = t >= ctaT ? prog(t, ctaT, dur / 1000) : t < HOOK_END / 1000 ? prog(t, 0, HOOK_END / 1000) : prog(t, HOOK_END / 1000, ctaT)
  let z = 1.06
  let pan: [number, number] = [0, 0]
  switch (g.camera) {
    case 'push-in': z = 1.02 + 0.1 * easeQuart(local); break
    case 'pull-out': z = 1.14 - 0.1 * easeQuart(local); break
    case 'pan-follow': z = 1.1; pan = [(local - 0.5) * 80, 0]; break
    case 'punch-in': z = 1.04 + (t % beat < beat * 0.18 ? 0.05 * (1 - (t % beat) / (beat * 0.18)) : 0) + 0.04 * local; break
    case 'handheld': z = 1.08; pan = [Math.sin(t * 1.7) * 6, Math.cos(t * 1.3) * 5]; break
    case 'detail-cuts': z = shot === 0 || shot === 3 ? 1.02 + 0.05 * local : 1.3 + 0.06 * local; pan = shot === 1 ? [-60, -40] : shot === 2 ? [70, 90] : [0, 0]; break
  }

  return { z, pan, shot }
}

/** `cam` + `grade`: the picture through the camera, with the reference's soft vignette. */
function drawPicture(ctx: Ctx, o: PaintOptions, z: number, pan: [number, number]): void {
  const { plan } = o
  const tw = plan.target.w
  const th = plan.target.h
  const vw = tw / z
  const vh = th / z
  const cx = Math.min(Math.max(o.focus.x * tw + pan[0], vw / 2), tw - vw / 2)
  const cy = Math.min(Math.max(o.focus.y * th + pan[1], vh / 2), th - vh / 2)
  ctx.save()
  ctx.scale(W / vw, H / vh)
  ctx.translate(-(cx - vw / 2), -(cy - vh / 2))
  if (o.colorBoost) ctx.filter = 'contrast(1.08) saturate(1.18)'
  const kx = o.bitmap.width / plan.source.w
  const ky = o.bitmap.height / plan.source.h
  if (plan.mode === 'cover') {
    ctx.drawImage(o.bitmap, plan.crop.x * kx, plan.crop.y * ky, plan.crop.w * kx, plan.crop.h * ky, 0, 0, tw, th)
  } else {
    const s = Math.max(tw / plan.source.w, th / plan.source.h)
    ctx.filter = `blur(40px) brightness(0.65)${o.colorBoost ? ' saturate(1.18)' : ''}`
    ctx.drawImage(o.bitmap, (tw - plan.source.w * s) / 2, (th - plan.source.h * s) / 2, plan.source.w * s, plan.source.h * s)
    ctx.filter = o.colorBoost ? 'contrast(1.08) saturate(1.18)' : 'none'
    ctx.drawImage(o.bitmap, plan.fg.x, plan.fg.y, plan.fg.w, plan.fg.h)
  }
  ctx.filter = 'none'
  ctx.restore()
  const vig = ctx.createRadialGradient(W / 2, H / 2, H * 0.3, W / 2, H / 2, H * 0.75)
  vig.addColorStop(0, 'rgba(0,0,0,0)')
  vig.addColorStop(1, 'rgba(0,0,0,0.28)')
  ctx.fillStyle = vig
  ctx.fillRect(0, 0, W, H)
}

function roundRect(ctx: Ctx, x: number, y: number, w: number, h: number, r: number): void {
  ctx.beginPath()
  ctx.roundRect(x, y, w, h, Math.min(r, h / 2, w / 2))
  ctx.fill()
}

/** One element at full visibility; the caller has applied the motion transform and alpha. */
function drawElement(ctx: Ctx, e: El, g: Genome, o: PaintOptions, rtl: boolean): void {
  const b = e.box
  if (e.role === 'scrim') {
    const grad = ctx.createLinearGradient(0, b.y, 0, b.y + b.h)
    grad.addColorStop(0, 'rgba(0,0,0,0)')
    grad.addColorStop(0.3, 'rgba(0,0,0,0.62)')
    grad.addColorStop(0.7, 'rgba(0,0,0,0.62)')
    grad.addColorStop(1, 'rgba(0,0,0,0)')
    ctx.fillStyle = grad
    ctx.fillRect(b.x, b.y, b.w, b.h)

    return
  }
  if (e.role === 'halo') {
    const r = Math.max(b.w, b.h) / 2
    const grad = ctx.createRadialGradient(b.x + b.w / 2, b.y + b.h / 2, r * 0.3, b.x + b.w / 2, b.y + b.h / 2, r)
    grad.addColorStop(0, 'rgba(255,255,255,0.22)')
    grad.addColorStop(1, 'rgba(255,255,255,0)')
    ctx.fillStyle = grad
    ctx.fillRect(b.x, b.y, b.w, b.h)

    return
  }
  if (e.role === 'frame') {
    ctx.strokeStyle = e.color ?? '#ffffff'
    ctx.lineWidth = 3
    ctx.strokeRect(b.x, b.y, b.w, b.h)

    return
  }
  if (e.role === 'brackets') {
    const L = 60
    ctx.strokeStyle = e.color ?? '#ffffff'
    ctx.lineWidth = 6
    ctx.beginPath()
    for (const [x, y, dx, dy] of [[b.x, b.y, 1, 1], [b.x + b.w, b.y, -1, 1], [b.x, b.y + b.h, 1, -1], [b.x + b.w, b.y + b.h, -1, -1]] as const) {
      ctx.moveTo(x + dx * L, y); ctx.lineTo(x, y); ctx.lineTo(x, y + dy * L)
    }
    ctx.stroke()

    return
  }
  if (e.rotate) {
    ctx.translate(b.x + b.w / 2, b.y + b.h / 2)
    ctx.rotate(e.rotate)
    ctx.translate(-(b.x + b.w / 2), -(b.y + b.h / 2))
  }
  if (e.fill && !e.lines) {
    ctx.fillStyle = e.fill
    roundRect(ctx, b.x, b.y, b.w, b.h, e.radius ?? 0)

    return
  }
  if (!e.lines) return

  const px = e.px ?? 48
  const weight = e.font === 'display' ? (o.fonts.displayWeight ?? DISPLAY_WEIGHT) : (o.fonts.textWeight ?? TEXT_WEIGHT)
  const family = e.font === 'display' ? o.fonts.display : o.fonts.text
  ctx.font = `${weight} ${px}px ${family}`
  ctx.direction = rtl ? 'rtl' : 'ltr'
  ctx.textBaseline = 'middle'
  ctx.textAlign = 'center'

  // A chip, pill or badge: its own fill behind one line.
  if (e.fill) {
    ctx.save()
    ctx.shadowColor = 'rgba(0,0,0,0.35)'; ctx.shadowBlur = 20; ctx.shadowOffsetY = 4
    ctx.fillStyle = e.fill
    roundRect(ctx, b.x, b.y, b.w, b.h, e.radius ?? 0)
    ctx.restore()
    ctx.fillStyle = e.color ?? '#141414'
    ctx.fillText(e.lines.join(' '), b.x + b.w / 2, b.y + b.h / 2)

    return
  }

  const lh = px * 1.25
  const xFor = (w: number) => (e.align === 'center' ? b.x + b.w / 2 : rtl ? b.x + b.w - w / 2 : b.x + w / 2)
  e.lines.forEach((line, i) => {
    const w = Math.min(b.w, ctx.measureText(line).width)
    const cy = b.y + lh * (i + 0.5)
    const x = xFor(w)
    const onShape = !!e.fillRgb
    if (!onShape && e.role === 'hook' && g.treatment === 'marker') {
      ctx.fillStyle = '#FFE14D'
      roundRect(ctx, x - w / 2 - px * 0.3, cy - px * 0.62, w + px * 0.6, px * 1.24, px * 0.2)
      ctx.fillStyle = '#141414'
      ctx.fillText(line, x, cy)

      return
    }
    if (!onShape && g.treatment === 'boxed') {
      ctx.fillStyle = 'rgba(0,0,0,0.55)'
      roundRect(ctx, x - w / 2 - px * 0.3, cy - px * 0.62, w + px * 0.6, px * 1.24, px * 0.25)
    }
    if (!onShape && g.treatment === 'accent-word' && i === 0 && e.role === 'hook') {
      ctx.fillStyle = 'rgba(255,255,255,0.18)'
      roundRect(ctx, x - w / 2 - px * 0.25, cy - px * 0.6, w + px * 0.5, px * 1.2, px * 0.6)
    }
    ctx.save()
    if (!onShape) { ctx.shadowColor = 'rgba(0,0,0,0.45)'; ctx.shadowBlur = px * 0.18; ctx.shadowOffsetY = 4 }
    ctx.fillStyle = e.color ?? '#FFFFFF'
    // The second line changes face, never to a thin weight: a hairline beside a display hook read as unfinished (Owner 2026-11-22).
    if (g.treatment === 'weight-contrast' && e.role === 'hook' && i > 0) ctx.font = `700 ${Math.round(px * 0.92)}px ${o.fonts.text}`
    ctx.fillText(line, x, cy)
    ctx.restore()
    if (g.treatment === 'underline' && e.role === 'hook') {
      ctx.fillStyle = '#FFE14D'
      ctx.fillRect(x - w / 2, cy + px * 0.55, w, Math.max(4, px * 0.06))
    }
  })
}

/** When an element is on screen in the film, and how far into its entrance it is. */
function presence(e: El, tMs: number, dur: number, index: number): { a: number; p: number } {
  const ctaT = dur - CTA_HOLD
  if (e.role === 'cta') return { a: tMs >= ctaT ? 1 : 0, p: easeOut(prog(tMs, ctaT + 100, ctaT + 500)) }
  if (e.role === 'hook') {
    const out = 1 - easeIO(prog(tMs, HOOK_END - 200, HOOK_END + 100))

    return { a: out, p: easeOut(prog(tMs, 150 + index * 120, 600 + index * 120)) }
  }
  if (e.decorative || e.role === 'panel' || e.role === 'ribbon' || e.role === 'band') {
    return { a: 1, p: easeOut(prog(tMs, 0, 400)) }
  }
  // message, support, price
  const start = HOOK_END + 150 + index * 220
  const out = 1 - easeIO(prog(tMs, ctaT - 150, ctaT + 100))

  return { a: tMs >= HOOK_END ? out : 0, p: easeOut(prog(tMs, start, start + 400)) }
}

/** Paints the candidate at `tMs` (`null` = the still: everything shown, camera at rest). */
export function paint(ctx: Ctx, elements: El[], g: Genome, o: PaintOptions, tMs: number | null, durationMs = DEFAULT_DURATION_MS, locale: 'ar' | 'en' = 'ar'): void {
  const rtl = locale === 'ar'
  ctx.clearRect(0, 0, W, H)
  const still = tMs === null
  const cam = still ? { z: 1.06, pan: [0, 0] as [number, number], shot: 0 } : cameraAt(g, tMs, durationMs)
  drawPicture(ctx, o, cam.z, cam.pan)

  if (!still) {
    // Transitions at each shot boundary.
    const beat = (60 / g.bpm) * 1000
    const sinceCut = (tMs - HOOK_END) % (beat * 4)
    const atCut = tMs >= HOOK_END && sinceCut < 120
    if (g.transition === 'flash' && atCut) { ctx.fillStyle = `rgba(255,255,255,${0.3 * (1 - sinceCut / 120)})`; ctx.fillRect(0, 0, W, H) }
    if (g.transition === 'shape-wipe' && tMs >= HOOK_END && tMs < HOOK_END + 350) {
      const k = prog(tMs, HOOK_END, HOOK_END + 350)
      ctx.fillStyle = 'rgba(255,225,77,0.9)'
      ctx.fillRect(rtl ? W * (1 - k) : 0, 0, W * Math.min(k, 1 - k) * 2, H)
    }
    // The end card: the frame darkens so the CTA reads on anything.
    const ctaT = durationMs - CTA_HOLD
    if (tMs >= ctaT) { ctx.fillStyle = `rgba(0,0,0,${0.47 * easeIO(prog(tMs, ctaT, ctaT + 400))})`; ctx.fillRect(0, 0, W, H) }
  }

  let hookIndex = 0
  let msgIndex = 0
  for (const e of elements) {
    let a = 1
    let p = 1
    if (!still) {
      const pr = presence(e, tMs, durationMs, e.role === 'hook' ? hookIndex++ : msgIndex++)
      a = pr.a
      p = pr.p
      // On the end card only the CTA and the decoration stay.
      if (tMs >= durationMs - CTA_HOLD && e.role !== 'cta' && !e.decorative && e.role !== 'price') a = 0
      if (tMs >= durationMs - CTA_HOLD && e.role === 'price' && g.endCard !== 'button-price') a = 0
    }
    if (a <= 0.001 || p <= 0) continue
    ctx.save()
    ctx.globalAlpha = a * (e.lines ? Math.min(1, p * 1.4) : p)
    if (!still && e.lines) {
      const b = e.box
      switch (g.motion) {
        case 'wipe': {
          const w = b.w * p
          ctx.beginPath(); ctx.rect(rtl ? b.x + b.w - w : b.x, b.y - 20, w, b.h + 40); ctx.clip()
          break
        }
        case 'mask-up': ctx.beginPath(); ctx.rect(b.x - 20, b.y + b.h * (1 - p), b.w + 40, b.h * p + 20); ctx.clip(); break
        case 'pop': {
          const s = 0.85 + 0.15 * easeQuart(p) + (p > 0.6 && p < 1 ? 0.04 * Math.sin((p - 0.6) * Math.PI / 0.4) : 0)
          ctx.translate(b.x + b.w / 2, b.y + b.h / 2); ctx.scale(s, s); ctx.translate(-(b.x + b.w / 2), -(b.y + b.h / 2))
          break
        }
        case 'slide': ctx.translate((rtl ? 1 : -1) * (1 - p) * 60, 0); break
        case 'blur-in': ctx.filter = `blur(${Math.round((1 - p) * 12)}px)`; break
        default: ctx.translate(0, (1 - p) * 18)
      }
    }
    drawElement(ctx, e, g, o, rtl)
    ctx.restore()
  }

  if (o.showMargins) {
    ctx.save()
    ctx.fillStyle = 'rgba(255,45,85,0.3)'
    ctx.fillRect(0, 0, W, H * 0.1)
    ctx.fillRect(0, H * 0.8, W, H * 0.2)
    ctx.fillRect(0, 0, W * 0.06, H)
    ctx.fillRect(W * 0.94, 0, W * 0.06, H)
    ctx.restore()
  }
}

export const CAMERA_SIZE = { w: Math.trunc(W * OVERSIZE), h: Math.trunc(H * OVERSIZE) }
