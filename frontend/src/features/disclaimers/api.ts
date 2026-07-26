import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

export type LocalizedText = { ar?: string; en?: string }

/** Mirrors DisclaimerResolver::resolve() — bilingual, merged system + scoped overrides. */
export interface ResolvedDisclaimer {
  version: number
  locale_default: 'ar' | 'en'
  enabled: Record<string, boolean>
  sections: {
    full?: LocalizedText
    short?: LocalizedText
    freshness?: LocalizedText
    methodology?: LocalizedText
    objectives?: Record<string, LocalizedText>
  }
  sources: string[]
}

/** Live resolved disclaimer for a project (dashboard/analytics). Reports carry their own snapshot. */
export function useDisclaimer(projectId: string | null) {
  return useQuery({
    queryKey: ['disclaimer', projectId],
    enabled: !!projectId,
    staleTime: 5 * 60_000,
    queryFn: () => getData<ResolvedDisclaimer>(`/projects/${projectId}/disclaimer`),
  })
}

/** Pick a section's text for a locale, falling back to Arabic then English. */
export function pickText(node: LocalizedText | undefined, locale: 'ar' | 'en'): string | null {
  if (!node) return null
  return node[locale] ?? node.ar ?? node.en ?? null
}
