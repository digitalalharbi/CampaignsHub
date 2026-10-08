import { getData, getEnvelope, postData, deleteData } from '@/lib/api/client'

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
  /**
   * How many of those follows this installation can place in TIME — never the same claim as
   * `clicks`, which covers all of time.
   *
   * Null means the endpoint did not ask the question (creating one link does not run the history
   * query); 0 means it asked and nothing was recorded. A surface must not read the first as the
   * second — see SHORT-LINK-HOPS-001.
   */
  recorded_follows: number | null
  last_clicked_at: string | null
  is_active: boolean
  created_at: string | null
}

/** One day inside the RECORDED period. A day before it is absent, never a zero. */
export interface ShortLinkDay {
  day: string
  follows: number
}

export interface ShortLinkHistory {
  id: string
  slug: string
  series: ShortLinkDay[]
  /** The counter: every follow since the link was made. */
  clicks_all_time: number
  /** Only what was placed in time, inside the window asked for. */
  recorded_in_window: number
}

export interface ShortLinkListMeta {
  total?: number
  limit?: number
  /** When this installation began writing follows down — null if it never has. */
  recording_since?: string | null
  /**
   * The workspace's own daily curve, already trimmed to the recorded period.
   *
   * EMPTY means nothing has been timed — which is not a flat line at zero. The page draws no curve
   * then and says why, because the links may well have been followed.
   */
  daily?: ShortLinkDay[]
}

export const listShortLinks = () => getData<ShortLink[]>('/short-links')

/**
 * The list AND the boundary that makes its numbers readable.
 *
 * `recording_since` is in `meta`, and without it a page showing «85 clicks» beside a curve of 12
 * would be inviting the reader to believe the curve is the story. See SHORT-LINK-HOPS-001.
 */
export const listShortLinksWithMeta = () =>
  getEnvelope<ShortLink[]>('/short-links') as Promise<{ data: ShortLink[]; meta?: ShortLinkListMeta }>

export const shortLinkHistory = (id: string, from?: string, to?: string) => {
  const q = new URLSearchParams()
  if (from !== undefined) q.set('from', from)
  if (to !== undefined) q.set('to', to)
  const suffix = q.toString() === '' ? '' : `?${q.toString()}`

  return getEnvelope<ShortLinkHistory>(`/short-links/${id}/history${suffix}`) as Promise<{
    data: ShortLinkHistory
    meta?: { from?: string; to?: string; recording_since?: string | null }
  }>
}

export const createShortLink = (kind: ShortLinkKind, value: string) =>
  postData<ShortLink>('/short-links', { kind, value })

export const disableShortLink = (id: string) => postData<ShortLink>(`/short-links/${id}/disable`, {})

/**
 * SHORT-LINKS-001 — remove it from the library.
 *
 * «Disable» stops a link resolving and leaves it on the screen. This takes it off the screen and
 * stops it resolving, which is what somebody means by «delete that». The server soft-deletes, so the
 * clicks it counted survive as audit.
 */
export const deleteShortLink = (id: string) => deleteData<null>(`/short-links/${id}`)

