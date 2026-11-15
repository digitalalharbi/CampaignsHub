import raw from './tokens.json'

/**
 * NOTIF-V2-EXTERNAL-001 — the ONE design-tokens module shared by the product UI and the external email.
 *
 * `tokens.json` is extracted from `styles/tokens.css` (the UI's own values, light and dark). The backend reads a
 * byte-identical copy at `backend/resources/design-tokens/tokens.json`; `DesignTokensSourceTest` (PHPUnit) fails when
 * the copy drifts or when a value here stops matching the CSS variable it was extracted from. The external email draws
 * every colour, radius and size from this module — nothing outside it.
 *
 * Use it where a CSS variable cannot reach: an SVG exported to an image, a canvas, the email preview frame.
 */
export type ThemeName = 'light' | 'dark'
export type StatusTone = 'on_track' | 'opportunity' | 'needs_follow_up'

export interface DesignTokens {
  version: number
  themes: Record<ThemeName, Record<string, string>>
  status: Record<StatusTone, { color: string; background: string }>
  platforms: Record<string, string>
  series: string[]
  radius: Record<string, string>
  typography: Record<string, string>
  space: Record<string, string>
  email: { width: string; font_ar: string; font_en: string; px: Record<string, string> }
}

export const DESIGN_TOKENS = raw as DesignTokens

/** A colour token's resolved value in a theme (`brand-600` → `#0d8a6f`). Unknown names throw, never blank. */
export function tokenColor(name: string, theme: ThemeName = 'light'): string {
  const value = DESIGN_TOKENS.themes[theme][name]
  if (value === undefined) throw new Error(`Unknown design token: ${name}`)

  return value
}

/** A platform's resolved colour (any registered spelling), falling back to the brand. */
export function platformHex(key: string, theme: ThemeName = 'light'): string {
  return tokenColor(DESIGN_TOKENS.platforms[key] ?? 'brand-600', theme)
}

/** The calm status colours of the external messages (no alarm red exists in this vocabulary). */
export function statusColors(tone: StatusTone, theme: ThemeName = 'light'): { color: string; background: string } {
  const s = DESIGN_TOKENS.status[tone]

  return { color: tokenColor(s.color, theme), background: tokenColor(s.background, theme) }
}

/** The chart series colours, resolved, in order. */
export function seriesHex(theme: ThemeName = 'light'): string[] {
  return DESIGN_TOKENS.series.map((name) => tokenColor(name, theme))
}
