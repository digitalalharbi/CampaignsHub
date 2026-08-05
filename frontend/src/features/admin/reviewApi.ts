import { getData, patchData } from '@/lib/api/client'

/**
 * REVIEW-001 — what each platform demands before it approves this application.
 *
 * `source` is the column that matters. A `derived` requirement is answered by the system on every
 * read — the redirect URI it will actually send, the scopes the connector requests — and cannot be
 * ticked, because a checklist somebody can mark complete without doing anything is one that lies.
 * A `declared` requirement happens inside the provider's own console, where this application has no
 * visibility, so the operator records it.
 */
export type ReviewStatus = 'missing' | 'ready' | 'submitted' | 'approved'

export interface ReviewItem {
  key: string
  source: 'derived' | 'declared'
  label_ar: string
  label_en: string
  why_ar: string
  why_en: string
  status: ReviewStatus
  /** The derived answer itself — a URL, a scope list — shown rather than asked for. */
  value?: string | null
  detail_ar?: string | null
  detail_en?: string | null
  note?: string | null
  updated_at?: string | null
  editable: boolean
}

export interface ReviewChecklist {
  provider: string
  label: string
  label_ar: string
  items: ReviewItem[]
  summary: {
    total: number
    missing: number
    ready: number
    submitted: number
    approved: number
    /** The only question this screen really answers. */
    submittable: boolean
  }
}

export const getReviewChecklists = () =>
  getData<{ providers: ReviewChecklist[] }>('/admin/integrations/review')

export const getReviewChecklist = (provider: string) =>
  getData<ReviewChecklist>(`/admin/integrations/review/${provider}`)

export const setReviewRequirement = (provider: string, requirement: string, body: { status: ReviewStatus; note?: string }) =>
  patchData<ReviewChecklist>(`/admin/integrations/review/${provider}/${requirement}`, body)
