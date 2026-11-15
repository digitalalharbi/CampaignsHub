import type { Bilingual } from './api'
import { useWords } from './strings'

interface TypeLike { term_labels: Record<string, Bilingual> }

/** The page's words with this workspace type's own terms («عميل / مشروع», «علامة / حملة», …) filled in from the server. */
export function useTypeWords(type: TypeLike | undefined) {
  const lang = useWords().ar ? 'ar' : 'en'
  const w = useWords(type ? { client: type.term_labels.client?.[lang] ?? '', project: type.term_labels.project?.[lang] ?? '' } : undefined)

  return { ...w, lang } as const
}
