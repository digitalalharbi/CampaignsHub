import type { Pixels } from '../analysis/pixels'
import { H, SAFE, W } from './constants'
import { contentHue, deriveContentTokens, palette, type Brand, type ContentTokens } from './contentTokens'
import { fitPlan, type FitPlan } from './fit'
import { edgesOf, lumaOf, sampleFrame, SAMPLE_H, SAMPLE_W } from './frame'
import { gate } from './gate'
import { ARCHETYPES, distance, genomeKey, randomGenome, type Archetype, type Genome } from './genome'
import type { MeasureWidth } from './layout'
import { rolesFor } from './palette'
import { hashSeed, mulberry32 } from './prng'
import { saliency } from './saliency'
import { score } from './score'
import { eligible, ensureContrast, solve, type Box, type Copy, type El } from './solve'

/**
 * CREATIVE-ENGINE-001 — `generateBatch`: one picture and one copy in, a batch of designs out, with no model anywhere.
 *
 * 1. Profile the picture: its focus and product box (saliency), the fit plan, and the fitted frame's edges and brightness.
 * 2. Compose ≥ 60 genomes from the seed, dropping archetypes the content cannot carry (a number hero needs a number).
 * 3. Solve each layout, add a scrim where bare text would fall under 4.5:1, and run the hard gate: a failure is dropped for good.
 * 4. Score what passes, then pick K by MMR (quality × difference), never the same archetype twice while others qualify.
 * The seed is returned with every design, so any of them can be made again exactly; «دفعة جديدة» is a new seed.
 */
export interface Profile { plan: FitPlan; product: Box; focus: { x: number; y: number }; tokens: ContentTokens; edges: Float32Array; luma: Float32Array }

export interface Candidate { id: string; seed: number; genome: Genome; elements: El[]; score: number; minContrast: number; as: 'video' | 'image' }

export interface Rejected { genome: Genome; reasons: string[] }

export interface BatchResult { profile: Profile; candidates: Candidate[]; rejected: Rejected[]; considered: number }

export function profileOf(src: Pixels, sourceSize: { w: number; h: number }, brand: Brand = {}): Profile {
  const small = downscaleTo(src, 96)
  const sal = saliency(small)
  const build = (framed: boolean, cardH = 0.62) => {
    const plan = fitPlan(sourceSize.w, sourceSize.h, { x: sal.cx, y: sal.cy }, framed, cardH)
    const frame = sampleFrame(src, plan)
    const luma = lumaOf(frame)
    const edges = edgesOf(luma, SAMPLE_W, SAMPLE_H)
    // The product box, carried from the source into frame pixels through the same plan.
    const toFrame = (nx: number, ny: number) => {
      const { w: tw, h: th } = plan.target
      const offX = (tw - W) / 2
      const offY = (th - H) / 2
      if (plan.mode === 'cover') {
        return { x: ((nx * plan.source.w - plan.crop.x) / plan.crop.w) * tw - offX, y: ((ny * plan.source.h - plan.crop.y) / plan.crop.h) * th - offY }
      }

      return { x: plan.fg.x + nx * plan.fg.w - offX, y: plan.fg.y + ny * plan.fg.h - offY }
    }
    const a = toFrame(sal.box.x, sal.box.y)
    const b = toFrame(sal.box.x + sal.box.w, sal.box.y + sal.box.h)
    const product = { x: Math.max(0, a.x), y: Math.max(0, a.y), w: Math.min(W, b.x) - Math.max(0, a.x), h: Math.min(H, b.y) - Math.max(0, a.y) }

    return { plan, product, luma, edges }
  }
  // A product that leaves less than 60% of the live height free above and below it cannot carry a hook, a line and a call without
  // covering it: the picture then sits as a card on its own blurred copy, the way a designer would frame a full-bleed close-up.
  const liveH = SAFE.y1 - SAFE.y0
  const freeOf = (p: { y: number; h: number }) => Math.max(0, p.y - SAFE.y0) + Math.max(0, SAFE.y1 - (p.y + p.h))
  let built = build(false)
  for (const cardH of [0.62, 0.52, 0.44, 0.38]) {
    if (freeOf(built.product) >= liveH * 0.6) break
    built = build(true, cardH)
  }
  const { plan, product, luma, edges } = built
  const hue = contentHue(downscaleTo(src, 90, 160), plan.focus)
  const tokens = deriveContentTokens(palette(downscaleTo(src, 64, 114)), hue, brand)

  return { plan, product, focus: plan.focus, tokens, edges, luma }
}

export interface GenerateInput {
  pixels: Pixels
  sourceSize: { w: number; h: number }
  copy: Copy
  measure: MeasureWidth
  locale: 'ar' | 'en'
  seed: number
  videos?: number
  images?: number
  brand?: Brand
  ctaSticker?: boolean
  /** Genomes (by key) this client used recently; the batch keeps its distance from them. */
  recent?: Genome[]
  /** Keep one archetype and vary the rest («تنويعات من هذا»). */
  lockArchetype?: Archetype
  /** A profile already computed for this picture (the caller needed its font pair first). */
  profile?: Profile
}

/** One genome against one copy: solved, scrimmed and gated — the same path the batch takes. */
export function solveOne(profile: Profile, g: Genome, input: Pick<GenerateInput, 'copy' | 'measure' | 'locale' | 'ctaSticker'>): { elements: El[]; ok: boolean; reasons: string[]; score: number; minContrast: number } {
  const ctx = { copy: input.copy, roles: rolesFor(profile.tokens, g.color), measure: input.measure, locale: input.locale, product: profile.product, edges: profile.edges, luma: profile.luma, ctaSticker: input.ctaSticker }
  const elements = ensureContrast(solve(g, ctx), ctx)
  const v = gate(elements, ctx)

  return { elements, ok: v.ok, reasons: v.reasons, minContrast: v.minContrast, score: v.ok ? score(elements, profile.product, v.minContrast) : 0 }
}

export function generateBatch(input: GenerateInput): BatchResult {
  const profile = input.profile ?? profileOf(input.pixels, input.sourceSize, input.brand)
  const rand = mulberry32(input.seed)
  const K = (input.videos ?? 4) + (input.images ?? 4)
  const passing: Array<Candidate & { _g: Genome }> = []
  const rejected: Rejected[] = []
  const seen = new Set<string>()
  const pool = ARCHETYPES.filter((a) => eligible(a, input.copy))
  let considered = 0

  for (let i = 0; i < 72; i++) {
    const g = randomGenome(rand, input.lockArchetype ?? pool[i % pool.length])
    const key = genomeKey(g)
    if (seen.has(key)) continue
    seen.add(key)
    considered++
    const r = solveOne(profile, g, input)
    if (!r.ok) { rejected.push({ genome: g, reasons: r.reasons }); continue }
    passing.push({ id: `${input.seed}-${i}`, seed: input.seed, genome: g, _g: g, elements: r.elements, score: r.score, minContrast: r.minContrast, as: 'image' })
  }

  // MMR: λ·quality + (1−λ)·distance to what is already chosen (and to the client's recent designs).
  const chosen: typeof passing = []
  const recent = input.recent ?? []
  while (chosen.length < K && chosen.length < passing.length) {
    let best: (typeof passing)[number] | null = null
    let bestVal = -Infinity
    for (const c of passing) {
      if (chosen.includes(c)) continue
      const usedArch = chosen.some((x) => x.genome.archetype === c.genome.archetype)
      const archLeft = passing.some((x) => !chosen.includes(x) && !chosen.some((y) => y.genome.archetype === x.genome.archetype))
      if (usedArch && archLeft && !input.lockArchetype) continue
      const dChosen = chosen.length ? Math.min(...chosen.map((x) => distance(x.genome, c.genome))) : 1
      const dRecent = recent.length ? Math.min(...recent.map((r) => distance(r, c.genome))) : 1
      const val = 0.6 * (c.score / 100) + 0.3 * dChosen + 0.1 * dRecent
      if (val > bestVal) { bestVal = val; best = c }
    }
    if (!best) break
    chosen.push(best)
  }
  const videos = input.videos ?? 4
  const candidates = chosen.map(({ _g, ...c }, i) => ({ ...c, as: i < videos ? ('video' as const) : ('image' as const) }))

  return { profile, candidates, rejected, considered }
}

function downscaleTo(src: Pixels, w: number, h?: number): Pixels {
  const tw = w
  const th = h ?? Math.max(1, Math.round((src.height / src.width) * w))
  const out = new Uint8ClampedArray(tw * th * 4)
  for (let y = 0; y < th; y++) for (let x = 0; x < tw; x++) {
    const sx = Math.min(src.width - 1, Math.floor(((x + 0.5) / tw) * src.width))
    const sy = Math.min(src.height - 1, Math.floor(((y + 0.5) / th) * src.height))
    const i = (sy * src.width + sx) * 4
    out.set([Number(src.data[i]), Number(src.data[i + 1]), Number(src.data[i + 2]), 255], (y * tw + x) * 4)
  }

  return { width: tw, height: th, data: out }
}

export const newSeed = (contentId: string, batch: number) => hashSeed(contentId, batch, Date.now())
