import { useQuery } from '@tanstack/react-query'
import { fetchClientBranding, useClientSpaceSlug, type ClientBranding } from './clientSpace'

/**
 * The agency's brand for the space the visitor is in (AGENCY-005).
 *
 * Keyed by slug, so moving between two spaces re-fetches rather than showing one brand over the
 * other's data — which is the failure this whole feature exists to prevent.
 *
 * `retry: false` because an unbranded agency is the normal case, not an error worth retrying, and a
 * branding request must never delay the page it decorates.
 */
export function useClientBranding(): ClientBranding | null {
  const slug = useClientSpaceSlug()

  const query = useQuery({
    queryKey: ['portal', 'branding', slug],
    queryFn: fetchClientBranding,
    retry: false,
    staleTime: 300_000,
  })

  return query.data ?? null
}

/**
 * The primary colour as an inline style, or nothing.
 *
 * Applied as a CSS variable override rather than by rewriting the design system: an agency supplies
 * a brand colour, not a whole theme, and letting one value redefine every token is how a portal ends
 * up unreadable in dark mode with a colour nobody checked.
 *
 * BOTH variable families are set, and the reason is not obvious: Tailwind v4 utilities like
 * `bg-brand-600` read `--color-brand-600`, while hand-written CSS in this codebase reads
 * `--brand-600`. Setting only the second looked correct in the inspector and changed nothing on
 * screen — which is exactly the kind of half-applied styling that ships.
 *
 * The value is validated as a hex colour before it reaches a style attribute: it is stored data, and
 * an arbitrary string in a CSS variable is a way to inject a value nobody reviewed.
 */
export function brandStyle(branding: ClientBranding | null): Record<string, string> {
  return accentStyle(branding?.colors?.primary)
}

/** The product's own accent, which every workspace has until it chooses one (BrandingController::DEFAULTS). */
const DEFAULT_ACCENT = '#0d8a6f'

/** BRANDING-HIERARCHY-001 — the agency portal's accent: only one the agency chose, and only one white text can be read on. */
export function agencyAccentFor(color: string | null | undefined): Record<string, string> | undefined {
  if (!color || color.toLowerCase() === DEFAULT_ACCENT) return undefined
  const style = accentStyle(color)

  return Object.keys(style).length > 0 ? style : undefined
}

/** WCAG contrast of a hex colour against white — buttons and active items set white text on the brand colour. */
export function contrastWithWhite(hex: string): number {
  const h = hex.slice(1)
  const full = h.length === 3 ? h.replace(/./g, (c) => c + c) : h.slice(0, 6)
  const [r, g, b] = [0, 2, 4].map((i) => {
    const v = parseInt(full.slice(i, i + 2), 16) / 255
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
  })
  const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b

  return 1.05 / (luminance + 0.05)
}

/** BRANDING-HIERARCHY-001 — the bar a customer's accent must clear before it replaces the product's own (WCAG AA, normal text). */
export const MIN_ACCENT_CONTRAST = 4.5

/**
 * The accent as CSS variables, or nothing. A colour too light for the white text set on it is not applied: an agency's pale
 * yellow must not turn every button into something nobody can read, in either theme or text direction.
 */
export function accentStyle(primary: unknown): Record<string, string> {
  if (typeof primary !== 'string' || !/^#[0-9a-f]{3}([0-9a-f]{3}([0-9a-f]{2})?)?$/i.test(primary)) {
    return {}
  }
  if (contrastWithWhite(primary) < MIN_ACCENT_CONTRAST) {
    return {}
  }

  return {
    '--brand-500': primary,
    '--brand-600': primary,
    '--brand-primary': primary,
    '--color-brand-500': primary,
    '--color-brand-600': primary,
  }
}
