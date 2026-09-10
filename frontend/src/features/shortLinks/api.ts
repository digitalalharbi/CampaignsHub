import { getData, postData } from '@/lib/api/client'

/**
 * SHORT-LINKS-001 — the whole contract, which is deliberately two fields.
 *
 * `value` rather than `phone` or `url`: the form has ONE field whose meaning follows the choice
 * above it. Naming it after either kind would put the technical distinction back into the request,
 * and the interface exists to keep it out.
 */
export type ShortLinkKind = 'whatsapp' | 'link'

export interface ShortLink {
  id: string
  slug: string
  kind: ShortLinkKind
  short_url: string
  /** What the person TYPED — a phone number stays a phone number, not the `wa.me` we derived. */
  shows: string
  clicks: number
  last_clicked_at: string | null
  is_active: boolean
  created_at: string | null
}

export const listShortLinks = () => getData<ShortLink[]>('/short-links')

export const createShortLink = (kind: ShortLinkKind, value: string) =>
  postData<ShortLink>('/short-links', { kind, value })

export const disableShortLink = (id: string) => postData<ShortLink>(`/short-links/${id}/disable`, {})
