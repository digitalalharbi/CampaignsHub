import type { Rect } from '../direction/safeZone'
import { clamp01, easeOut } from './easing'

/**
 * CONTENT-STUDIO-001 batch 5 — the motion recipes: one small function per effect, from progress (0 → 1) to a transform. A family names
 * the recipes it uses; the same function runs in the live preview and in the exporter. Arabic reads right to left, so the reveals that
 * have a direction start from the side the language starts on and never cut a letter in half: a wipe reveals whole pixels of a line,
 * and the line is already laid out in full.
 */
export type Direction = 'rtl' | 'ltr'

export interface Transform {
  opacity: number
  translateX: number
  translateY: number
  scaleX: number
  scaleY: number
  /** Fraction of the layer's width revealed, from the start side; 1 = whole layer. */
  reveal: number
}

export interface RecipeContext {
  direction: Direction
  rect: Rect
}

export type Recipe = (progress: number, ctx: RecipeContext) => Transform

const REST: Transform = { opacity: 1, translateX: 0, translateY: 0, scaleX: 1, scaleY: 1, reveal: 1 }
const base = (over: Partial<Transform>): Transform => ({ ...REST, ...over })

/** The sign of «toward the start of the language»: -1 moves left (English starts left), +1 moves right (Arabic starts right). */
const startSign = (d: Direction): number => (d === 'rtl' ? 1 : -1)

export const RECIPES: Readonly<Record<string, Recipe>> = {
  fade: (p) => base({ opacity: easeOut(p) }),
  rise: (p, { rect }) => base({ opacity: easeOut(p), translateY: (1 - easeOut(p)) * rect.h * 0.6 }),
  'mask-wipe-rtl': (p) => base({ reveal: easeOut(p), opacity: p > 0 ? 1 : 0 }),
  'bar-grow-start': (p) => base({ scaleX: Math.max(0.0001, easeOut(p)), opacity: p > 0 ? 1 : 0 }),
  'zoom-punch': (p) => base({ scaleX: 1 + (1 - easeOut(p)) * 0.25, scaleY: 1 + (1 - easeOut(p)) * 0.25, opacity: clamp01(p * 4) }),
  'badge-pop': (p) => {
    // A small overshoot: 0 → 1.12 → 1.
    const s = p < 0.7 ? easeOut(p / 0.7) * 1.12 : 1.12 - ((p - 0.7) / 0.3) * 0.12

    return base({ scaleX: s, scaleY: s, opacity: clamp01(p * 5) })
  },
  /** The slow move on the picture itself, driven by the whole clip's progress rather than an enter. */
  'ken-burns': (p) => base({ scaleX: 1 + 0.08 * p, scaleY: 1 + 0.08 * p }),
  parallax: (p, { direction }) => base({ translateX: -startSign(direction) * 24 * p }),
  /** A hard cut on the beat: nothing until the cut, then whole. */
  'beat-cut': (p) => base({ opacity: p >= 1 ? 1 : 0 }),
}

/** A recipe by key; an unknown key is a plain fade, never an error — a missing effect must not lose the output. */
export const recipeFor = (key: string): Recipe => RECIPES[key] ?? RECIPES.fade!

/** The rectangle of a layer after its transform — what the exporter and the margin check should look at. */
export function transformedRect(rect: Rect, t: Transform): Rect {
  const w = rect.w * t.scaleX
  const h = rect.h * t.scaleY

  return { x: rect.x + t.translateX - (w - rect.w) / 2, y: rect.y + t.translateY - (h - rect.h) / 2, w, h }
}
