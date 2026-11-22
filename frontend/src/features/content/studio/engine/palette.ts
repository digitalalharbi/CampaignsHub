import { contrast, hex, hsvToRgb, rgbToHsv, type RGB } from './color'
import type { ContentTokens } from './contentTokens'
import type { ColorStrategy } from './genome'

/**
 * CREATIVE-ENGINE-001 — the colour strategies. Every one is derived from the content tokens (never a random colour): the content
 * accent itself, its complement, a neighbour on the wheel, a dark luxury surface or a light editorial one. Text on each fill is
 * whichever of white or near-black reads better, so a role colour can never be the reason a design fails contrast.
 */
export interface Roles { accent: string; onAccent: string; panel: string; onPanel: string; accentRgb: RGB; panelRgb: RGB; onAccentRgb: RGB; onPanelRgb: RGB }

const WHITE: RGB = [255, 255, 255]
const INK: RGB = [20, 20, 20]
const readable = (bg: RGB): RGB => (contrast(WHITE, bg) >= contrast(INK, bg) ? WHITE : INK)

export function rolesFor(tokens: ContentTokens, strategy: ColorStrategy): Roles {
  const [h, s, v] = rgbToHsv(tokens.accent)
  let accent: RGB = tokens.accent
  let panel: RGB = tokens.surface
  if (strategy === 'complementary') accent = hsvToRgb((h + 0.5) % 1, Math.max(s, 0.45), Math.max(v, 0.55))
  if (strategy === 'analogous') accent = hsvToRgb((h + 1 / 12) % 1, Math.max(s, 0.45), Math.max(v, 0.55))
  if (strategy === 'dark-luxury') { panel = hsvToRgb(h, 0.35, 0.14); accent = hsvToRgb(h, Math.min(s, 0.45), 0.78) }
  if (strategy === 'light-editorial') { panel = hsvToRgb(h, 0.04, 0.98); accent = hsvToRgb(h, Math.max(s, 0.5), Math.min(v, 0.5)) }
  const onAccent = readable(accent)
  const onPanel = readable(panel)

  return { accent: hex(accent), onAccent: hex(onAccent), panel: hex(panel), onPanel: hex(onPanel), accentRgb: accent, panelRgb: panel, onAccentRgb: onAccent, onPanelRgb: onPanel }
}
