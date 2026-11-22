import { pick } from './prng'

/**
 * CREATIVE-ENGINE-001 — a design is a genome: one choice on each independent axis. The engine composes many, the gate removes the
 * ones that break a rule, the score ranks the rest, and the selector keeps the best that are also different from each other.
 */
export const ARCHETYPES = [
  'side-stack', 'bold-top-hook', 'offer-badge', 'surface-card', 'editorial-frame', 'minimal-center',
  'number-hero', 'word-cascade', 'ribbon-banner', 'corner-brackets', 'diagonal-band', 'product-spotlight',
] as const
export type Archetype = (typeof ARCHETYPES)[number]

export const TREATMENTS = ['shadow', 'marker', 'boxed', 'accent-word', 'weight-contrast', 'underline'] as const
export type Treatment = (typeof TREATMENTS)[number]

export const SHAPES = ['none', 'glass', 'solid', 'ribbon', 'frame', 'brackets'] as const
export type Shape = (typeof SHAPES)[number]

export const COLOR_STRATEGIES = ['content', 'complementary', 'analogous', 'dark-luxury', 'light-editorial'] as const
export type ColorStrategy = (typeof COLOR_STRATEGIES)[number]

export const CAMERAS = ['push-in', 'pull-out', 'pan-follow', 'punch-in', 'handheld', 'detail-cuts'] as const
export type Camera = (typeof CAMERAS)[number]

export const TEXT_MOTIONS = ['wipe', 'word-rise', 'pop', 'slide', 'blur-in', 'mask-up'] as const
export type TextMotion = (typeof TEXT_MOTIONS)[number]

export const TRANSITIONS = ['cut', 'flash', 'zoom-through', 'dissolve', 'shape-wipe'] as const
export type Transition = (typeof TRANSITIONS)[number]

export const END_CARDS = ['button', 'button-price', 'block', 'surface', 'frame'] as const
export type EndCard = (typeof END_CARDS)[number]

export interface Genome {
  archetype: Archetype
  treatment: Treatment
  shape: Shape
  color: ColorStrategy
  camera: Camera
  motion: TextMotion
  transition: Transition
  endCard: EndCard
  bpm: number
}

export function randomGenome(rand: () => number, archetype?: Archetype): Genome {
  return {
    archetype: archetype ?? pick(rand, ARCHETYPES),
    treatment: pick(rand, TREATMENTS),
    shape: pick(rand, SHAPES),
    color: pick(rand, COLOR_STRATEGIES),
    camera: pick(rand, CAMERAS),
    motion: pick(rand, TEXT_MOTIONS),
    transition: pick(rand, TRANSITIONS),
    endCard: pick(rand, END_CARDS),
    bpm: 90 + Math.floor(rand() * 39),
  }
}

/** Weighted Hamming distance on the axes (0..1): the archetype counts most, then treatment, shape and camera. */
const WEIGHTS: Record<keyof Omit<Genome, 'bpm'>, number> = { archetype: 3, treatment: 2, shape: 2, color: 1.5, camera: 1.5, motion: 1, transition: 0.5, endCard: 0.5 }
const TOTAL = Object.values(WEIGHTS).reduce((a, b) => a + b, 0)

export function distance(a: Genome, b: Genome): number {
  let d = 0
  for (const k of Object.keys(WEIGHTS) as Array<keyof typeof WEIGHTS>) if (a[k] !== b[k]) d += WEIGHTS[k]

  return d / TOTAL
}

export const genomeKey = (g: Genome): string => [g.archetype, g.treatment, g.shape, g.color, g.camera, g.motion, g.transition, g.endCard, g.bpm].join('.')

/** How many distinct designs the axes can make — the size of the space the engine searches. */
export const SPACE = ARCHETYPES.length * TREATMENTS.length * SHAPES.length * COLOR_STRATEGIES.length * CAMERAS.length * TEXT_MOTIONS.length * TRANSITIONS.length * END_CARDS.length * 39
