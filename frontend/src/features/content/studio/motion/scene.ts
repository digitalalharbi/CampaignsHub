import type { StudioCopy } from '../direction/copyRules'
import { FORMATS, type FormatKey, type Layer, type Rect } from '../direction/safeZone'
import type { VideoTimeline } from '../direction/timeline'
import type { Family, Role } from '../families/familySchema'
import { fontSizes, MIN_PX, type DesignTokens } from '../tokens/tokens'
import { snapToBeat } from './beats'
import { fitText, type Measure } from './text'

/**
 * CONTENT-STUDIO-001 batch 5 — the scene: everything the renderer needs to draw any frame, as plain data. The previewer draws it live
 * and the exporter draws the same scene frame by frame, so what is seen is what is exported.
 */
export interface SceneLayer {
  id: string
  role: Role
  kind: Layer['kind']
  rect: Rect
  /** Wrapped lines, empty for a shape. */
  lines: string[]
  fontPx: number
  font: 'display' | 'text'
  color: string
  fill: string | null
  enterAt: number
  enterMs: number
  recipe: string
}

export interface Scene {
  format: FormatKey
  width: number
  height: number
  /** 0 for an image. */
  durationMs: number
  direction: 'rtl' | 'ltr'
  layers: SceneLayer[]
  /** Layers whose copy did not fit at the smallest legible size: shorten the copy or enlarge the block. */
  overflow: string[]
}

export interface BuildSceneInput {
  family: Family
  layers: ReadonlyArray<Layer>
  copy: StudioCopy
  tokens: DesignTokens
  format: FormatKey
  locale: 'ar' | 'en'
  /** The text colour the picture's calm place asked for. */
  tone: 'light' | 'dark'
  measure: Measure
  /** Absent for an image. */
  timeline?: VideoTimeline
  beatsMs?: ReadonlyArray<number>
}

const FONT_FOR: Record<Role, 'display' | 'text'> = { hook: 'display', price: 'display', cta: 'display', message: 'text', support: 'text', logo: 'text', surface: 'text' }
const SIZE_FOR: Record<Role, keyof typeof MIN_PX> = { hook: 'hook', price: 'headline', cta: 'cta', message: 'message', support: 'message', logo: 'message', surface: 'message' }

export function buildScene(input: BuildSceneInput): Scene {
  const { family, copy, tokens, format, locale, measure } = input
  const { width, height } = FORMATS[format]
  const sizes = fontSizes(tokens, width)
  const motion = family.motion
  const overflow: string[] = []
  const hasSurface = input.layers.some((l) => l.kind === 'bar')
  const text: Partial<Record<Role, string>> = { hook: copy.hook, message: copy.message, cta: copy.cta, support: copy.support ?? '', price: copy.support ?? '' }
  const tl = input.timeline
  const beats = input.beatsMs ?? []

  const layers: SceneLayer[] = input.layers.map((l, i) => {
    const role = (l.id === 'surface' ? 'surface' : l.id) as Role
    const onSurface = hasSurface && (role === 'hook' || role === 'message' || role === 'support')
    const isCta = role === 'cta'
    const content = text[role] ?? ''
    const startPx = sizes[SIZE_FOR[role]]
    const fit = role === 'logo' || role === 'surface' ? { lines: [], px: startPx, overflow: false } : fitText(measure, content, l.rect.w, l.rect.h, startPx, Math.max(MIN_PX[SIZE_FOR[role]] * (width / 1080) * 0.75, 20))

    if (fit.overflow) overflow.push(role)

    // Recipes are used in the order the family names them: the first for the hook, the next ones for the rest, the last for the bar.
    const recipe = isCta ? 'rise' : role === 'surface' ? (motion.find((m) => m.startsWith('bar-')) ?? 'fade') : motion[Math.min(i, motion.length - 1)] ?? 'fade'
    const enterAt = !tl ? 0 : role === 'hook' || role === 'logo' || role === 'surface' ? 0 : isCta ? tl.cta.start : snapToBeat(tl.message.start + (role === 'support' || role === 'price' ? 600 : 0), beats)

    return {
      id: l.id,
      role,
      kind: l.kind,
      rect: l.rect,
      lines: fit.lines,
      fontPx: fit.px,
      font: FONT_FOR[role],
      color: isCta ? tokens.colors.accentText : onSurface ? tokens.colors.surfaceText : input.tone === 'light' ? tokens.colors.textOnDark : tokens.colors.textOnLight,
      fill: isCta ? tokens.colors.accent : role === 'surface' ? tokens.colors.surface : role === 'price' ? tokens.colors.accent : null,
      enterAt,
      // The hook shows within the first half second; everything else enters at the tokens' pace.
      enterMs: role === 'hook' ? Math.min(450, tokens.motion.enterMs) : isCta ? 300 : tokens.motion.enterMs,
      recipe: tl ? recipe : 'fade',
    }
  })

  return { format, width, height, durationMs: tl?.duration ?? 0, direction: locale === 'ar' ? 'rtl' : 'ltr', layers, overflow }
}
