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
  const primary = branding?.colors?.primary

  if (typeof primary !== 'string' || !/^#[0-9a-f]{3}([0-9a-f]{3}([0-9a-f]{2})?)?$/i.test(primary)) {
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
