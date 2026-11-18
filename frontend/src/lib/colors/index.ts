import { canonicalPlatform, PLATFORM_ORDER, type AdPlatform } from '@/lib/platforms'

/**
 * COLOUR-SYSTEM-001 — the ONE colour vocabulary of the product: a colour per PLATFORM and a colour per BUDGET STATUS.
 *
 * Both are theme tokens (`styles/tokens.css`), never literals, so each theme states its own value and a chart, a chip
 * and a legend dot cannot disagree about what «Meta» or «exceeded» looks like. Every screen that draws a platform or a
 * budget state should read it from here:
 *
 *   platform  → `platformColor(key)`   (any spelling: `google_ads`, `facebook`, … canonicalised)
 *   status    → `budgetStatus(ratio)` then `STATUS_COLOR[status]` / `STATUS_SOFT[status]` / `STATUS_TEXT[status]`
 *
 * ## Platforms: one colour each, no two alike, none of them the status hues
 *
 * Green, orange and red mean «within / near / exceeded» everywhere, so no platform is allowed to look like one of
 * them (the old Google red and ChatGPT green did). The two black brands are no longer both black: TikTok keeps
 * black (near-white on dark), X is slate. Contrast on the page ground is ≥ 3:1 in both themes for every value.
 *
 * ## Status: green within, orange near, red exceeded — and never colour alone
 *
 * `within` is below the `near` share of the budget (80%), `near` is from there up to the budget, `exceeded` is at or
 * beyond it, `unknown` is no reading (no budget, nothing measured, mixed currencies) and is drawn neutral. The server
 * sends the same threshold (`thresholds.near`), so the page and the API cannot read a ratio two ways. A chip always
 * carries its WORD beside the colour (`STATUS_LABEL`), because colour alone is not an accessible signal.
 */

/** The platform colours as theme tokens, by canonical key — `var(--platform-*)`. */
export const PLATFORM_COLOR: Record<AdPlatform, string> = {
  snapchat: 'var(--platform-snapchat)',
  tiktok: 'var(--platform-tiktok)',
  meta: 'var(--platform-meta)',
  google: 'var(--platform-google)',
  x: 'var(--platform-x)',
  linkedin: 'var(--platform-linkedin)',
  openai_ads: 'var(--platform-openai)',
}

/** Same map, plus the spellings providers register under (kept for every existing `PLATFORM_COLORS[key]` read). */
export const PLATFORM_COLORS: Record<string, string> = { ...PLATFORM_COLOR, google_ads: PLATFORM_COLOR.google }

export const platformColor = (key: string): string => PLATFORM_COLORS[key] ?? PLATFORM_COLOR[canonicalPlatform(key) as AdPlatform] ?? 'var(--brand-500)'

/** The platform keys in the product's one order — for a legend or a series list. */
export const PLATFORM_KEYS = PLATFORM_ORDER

export type BudgetStatus = 'within' | 'near' | 'exceeded' | 'unknown'

/** The share of a budget at which a unit reads «near». The API states the same number (`thresholds.near`). */
export const NEAR_SHARE = 0.8

/** Where a spend-to-budget ratio stands. `null` (no reading) is `unknown`, never `within`. */
export function budgetStatus(ratio: number | null | undefined, near: number = NEAR_SHARE): BudgetStatus {
  if (ratio === null || ratio === undefined || !Number.isFinite(ratio)) return 'unknown'
  if (ratio >= 1) return 'exceeded'
  if (ratio >= near) return 'near'

  return 'within'
}

/** Fill / stroke for a mark or a bar. */
export const STATUS_COLOR: Record<BudgetStatus, string> = {
  within: 'var(--success)',
  near: 'var(--warning)',
  exceeded: 'var(--danger)',
  unknown: 'var(--text-muted)',
}

/** The tinted ground for a chip or a strip. */
export const STATUS_SOFT: Record<BudgetStatus, string> = {
  within: 'var(--positive-background)',
  near: 'var(--warning-background)',
  exceeded: 'var(--negative-background)',
  unknown: 'var(--surface-secondary)',
}

/** Tailwind text classes that keep the word readable on its tinted ground, in both themes. */
export const STATUS_TEXT: Record<BudgetStatus, string> = {
  within: 'text-success',
  near: 'text-warning',
  exceeded: 'text-danger',
  unknown: 'text-text-muted',
}

export const STATUS_LABEL: Record<BudgetStatus, { ar: string; en: string }> = {
  within: { ar: 'ضمن الميزانية', en: 'Within budget' },
  near: { ar: 'قريب من الحد', en: 'Near the limit' },
  exceeded: { ar: 'تجاوز الميزانية', en: 'Over budget' },
  unknown: { ar: 'بلا قراءة', en: 'No reading' },
}

/** Series colours for a chart with no platform meaning (spend vs budget, trends). */
export const SERIES_COLORS = ['var(--brand-600)', 'var(--info)', 'var(--purple)', 'var(--teal)'] as const
