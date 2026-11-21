import { analyzeImage, type ImageAnalysis } from '../analysis/analyzeImage'
import type { Pixels } from '../analysis/pixels'
import { hardChecks, type HardFailure } from '../direction/hardChecks'
import type { StudioCopy } from '../direction/copyRules'
import type { Layer } from '../direction/safeZone'
import { buildTimeline, type VideoTimeline } from '../direction/timeline'
import { placeFamily } from '../families/placeFamily'
import { pickBatch, type BatchPick } from '../families/selectBatch'
import { buildScene, type Scene } from '../motion/scene'
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
}

export function buildStudioBatch(input: BuildBatchInput): StudioBatch {
  const analysis = input.analysis ?? analyzeImage(input.pixels)
  const tokens = input.tokens ?? deriveTokens({ swatches: analysis.swatches, classes: analysis.classes }, input.brand)
  const picks = pickBatch({ videos: input.videos ?? 3, images: input.images ?? 4, round: input.round, scores: input.scores })
  const tone: 'light' | 'dark' = analysis.classes.tone === 'dark' ? 'light' : 'dark'

  const outputs = picks.map((pick): BatchOutput => {
    const layers = placeFamily(pick.family, analysis, '9x16', input.locale)
    const timeline = pick.as === 'video' ? buildTimeline() : undefined
    const scene = buildScene({ family: pick.family, layers, copy: input.copy, tokens, format: '9x16', locale: input.locale, tone, measure: input.measure, timeline, beatsMs: input.beatsMs })
    const hard = hardChecks({ format: '9x16', layers, copy: input.copy, timeline, forbiddenWords: input.forbiddenWords })

    return { as: pick.as, pick, layers, scene, timeline, hard }
  })

  return { analysis, tokens, lint: lintTokens(tokens), outputs }
}
