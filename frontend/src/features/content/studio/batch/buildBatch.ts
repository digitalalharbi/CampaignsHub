import { analyzeImage, type ImageAnalysis } from '../analysis/analyzeImage'
import type { Pixels } from '../analysis/pixels'
import { hardChecks, type HardFailure } from '../direction/hardChecks'
import type { StudioCopy } from '../direction/copyRules'
import { FORMATS, type Layer } from '../direction/safeZone'
import { buildTimeline, type VideoTimeline } from '../direction/timeline'
import { placeFamily } from '../families/placeFamily'
import { pickBatch, type BatchPick } from '../families/selectBatch'
import { buildScene, type Scene } from '../motion/scene'
import { LOOK_BY_FAMILY, type Look, type SceneStyle } from '../motion/look'
import { MIN_CONTRAST, whiteTextContrast } from '../motion/contrast'
import type { Measure } from '../motion/text'
import { deriveTokens, lintTokens, type BrandKit, type DesignTokens, type LintIssue } from '../tokens/tokens'

/**
 * CONTENT-STUDIO-001 batch 7 — one batch from one picture: analysis → tokens → families → placed scenes, each checked against the hard
 * rules before anything is shown. Pure: the browser supplies the pixels and the measurer; nothing here draws, encodes, stores or calls out.
 */
export interface BatchOutput {
  as: 'video' | 'image'
  pick: BatchPick
  layers: Layer[]
  scene: Scene
  timeline: VideoTimeline | undefined
  /** Facts about the file: any entry means «cannot be saved until corrected». */
  hard: HardFailure[]
  /** Things worth a look that do not block saving: for now, copy that sits over the product because the picture left no clear place. */
  warnings: Array<'text_over_product'>
  /** CONTENT-LIBRARY-SNAP-001 — the weakest text contrast in the look drawn (WCAG ratio); never below 4.5 after the gate. */
  contrast: number
}

export interface StudioBatch {
  analysis: ImageAnalysis
  tokens: DesignTokens
  lint: LintIssue[]
  outputs: BatchOutput[]
}

export interface BuildBatchInput {
  pixels: Pixels
  copy: StudioCopy
  locale: 'ar' | 'en'
  measure: Measure
  brand?: BrandKit
  /** Reuse an earlier analysis, so «new batch» and «swap style» do not read the picture again. */
  analysis?: ImageAnalysis
  /** Reuse tokens (e.g. after a style swap). */
  tokens?: DesignTokens
  videos?: number
  images?: number
  round?: number
  scores?: Readonly<Record<string, number>>
  beatsMs?: ReadonlyArray<number>
  forbiddenWords?: ReadonlyArray<string>
  /** CONTENT-LIBRARY-SNAP-001 — the enhancements chosen (sticker CTA, colour); the look itself comes from the family. */
  style?: Omit<SceneStyle, 'look'>
  /** «تحسينات النص»: several copies, one per output in turn, instead of the one copy on every design. */
  variants?: ReadonlyArray<StudioCopy>
}

/** #111 on the marker highlight #FFE14D, and on white: both well above 4.5:1. */
const MARKER_CONTRAST = 14

export function buildStudioBatch(input: BuildBatchInput): StudioBatch {
  const analysis = input.analysis ?? analyzeImage(input.pixels)
  const tokens = input.tokens ?? deriveTokens({ swatches: analysis.swatches, classes: analysis.classes }, input.brand)
  const picks = pickBatch({ videos: input.videos ?? 3, images: input.images ?? 4, round: input.round, scores: input.scores })
  const tone: 'light' | 'dark' = analysis.classes.tone === 'dark' ? 'light' : 'dark'

  const outputs = picks.map((pick, i): BatchOutput => {
    const copy = input.variants && input.variants.length > 0 ? input.variants[i % input.variants.length]! : input.copy
    const skip = (copy.support ?? '').trim() === '' ? (['support', 'price'] as const) : []
    const layers = placeFamily(pick.family, analysis, '9x16', input.locale, { skip })
    const timeline = pick.as === 'video' ? buildTimeline() : undefined
    const built = buildScene({ family: pick.family, layers, copy, tokens, format: '9x16', locale: input.locale, tone, measure: input.measure, timeline, beatsMs: input.beatsMs })
    const canvasSize = FORMATS['9x16']
    const textLayers = built.layers.filter((l) => l.lines.length > 0 && (l.role === 'hook' || l.role === 'message' || l.role === 'support'))
    const weakest = (look: Look) => Math.min(Infinity, ...textLayers.map((l) => whiteTextContrast(look, analysis.grid, l.rect, canvasSize, look === 'glass' && l.role !== 'hook')))
    let look: Look = LOOK_BY_FAMILY[pick.family.key] ?? 'glass'
    let contrast = look === 'marker' ? MARKER_CONTRAST : weakest(look)
    // The gate: white text that would not reach 4.5:1 on this picture is drawn on its own highlight instead.
    if (contrast < MIN_CONTRAST) { look = 'marker'; contrast = MARKER_CONTRAST }
    const scene: Scene = { ...built, style: { look, ...input.style } }
    const hard = hardChecks({ format: '9x16', layers, copy, timeline, forbiddenWords: input.forbiddenWords })

    const canvas = FORMATS['9x16']
    const p = analysis.product
    const box = { x: p.x * canvas.width, y: p.y * canvas.height, w: p.w * canvas.width, h: p.h * canvas.height }
    const over = layers.some((l) => (l.kind === 'text' || l.kind === 'price') && l.rect.x < box.x + box.w && l.rect.x + l.rect.w > box.x && l.rect.y < box.y + box.h && l.rect.y + l.rect.h > box.y)

    return { as: pick.as, pick, layers, scene, timeline, hard, warnings: over ? ['text_over_product'] : [], contrast }
  })

  return { analysis, tokens, lint: lintTokens(tokens), outputs }
}
