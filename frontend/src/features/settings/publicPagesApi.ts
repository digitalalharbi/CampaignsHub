import { getData, postData, putData } from '@/lib/api/client'

/** One editable public surface. Mirrors backend PublicPageSettingsController. */
export type PublicPageKey = 'home' | 'portal_paid' | 'portal_influencer' | 'portal_tracking'

/** A section is a stable KEY the public page renders; the editor owns its copy, order and on/off. */
export interface PageSection {
  enabled?: boolean
  order?: number
  title?: string
  subtitle?: string
  eyebrow?: string
  desc?: string
  tagline?: string
  primary_cta?: { label: string; to: string }
  secondary_cta?: { label: string; to: string }
  [k: string]: unknown
}

export type PageContent = Record<string, PageSection | boolean | undefined>

export interface PublicPageRow {
  page: PublicPageKey
  draft: PageContent
  published: PageContent | null
  is_published: boolean
  has_unpublished_changes: boolean
  version: number
  published_at: string | null
  defaults: PageContent
}

export const listPublicPages = () => getData<PublicPageRow[]>('/settings/public-pages')

export const savePublicPageDraft = (page: PublicPageKey, draft: PageContent) =>
  putData<PublicPageRow>(`/settings/public-pages/${page}`, { draft })

export const publishPublicPage = (page: PublicPageKey) =>
  postData<PublicPageRow>(`/settings/public-pages/${page}/publish`)

export const revertPublicPageDraft = (page: PublicPageKey) =>
  postData<PublicPageRow>(`/settings/public-pages/${page}/revert`)

/** PUBLIC read (no auth) — what visitors actually get. */
export const getPublishedPage = (page: PublicPageKey) =>
  getData<{ page: string; content: PageContent; source: 'published' | 'defaults'; version: number }>(`/public/pages/${page}`)
