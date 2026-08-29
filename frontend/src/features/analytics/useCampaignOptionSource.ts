import { useCallback, useMemo, useRef, useState } from 'react'
import { useDebouncedValue } from '@/components/forms/useTypeahead'
import { useCampaignOptions } from './api'

/**
 * UX-MULTISELECT-SCALE-002 — the one campaign-option source, shared by every surface that filters
 * by campaign.
 *
 * Each surface used to build its own list out of `useCampaigns`, the breakdown: windowed by the
 * chosen period, and a full metrics row per campaign to fill a control that needs a name. Two
 * copies of that is also two places for the same estate to be described differently.
 *
 * The term is debounced here rather than in the control, because the term's cost is a request and
 * the thing that owns the request should own its rate.
 */
export function useCampaignOptionSource(projectId: string | null) {
  const [term, setTerm] = useState('')
  const debounced = useDebouncedValue(term.trim(), 250)
  const query = useCampaignOptions(projectId, debounced)

  /*
   * Names outlive the page they arrived on.
   *
   * The server returns the CURRENT term's matches, so a campaign the reader already selected is
   * routinely absent from `options` — and both the closed control and the applied-filter chips read
   * their label out of it. Without this memory, selecting a campaign and then typing anything that
   * does not match it turns the reader's own choice into a bare uuid.
   */
  const names = useRef(new Map<string, string>())
  for (const o of query.data?.options ?? []) names.current.set(o.id, o.name)

  const options = useMemo(
    () => (query.data?.options ?? []).map((o) => ({ value: o.id, label: o.name })),
    [query.data],
  )

  /*
   * Stable identities, because callers put this in dependency arrays.
   *
   * Returning a fresh object every render would silently defeat the `useMemo` that builds the
   * applied-filter chips on both surfaces — it would rebuild on every render of the page, which is
   * the memo doing nothing while looking like it does something.
   */
  const labelOf = useCallback((id: string) => names.current.get(id) ?? id, [])

  const hasMore = query.data?.has_more === true
  const loading = query.isFetching
  const search = useMemo(
    /* A fact from the server, never inferred from a full page. */
    () => ({ term, onTerm: setTerm, hasMore, loading }),
    [term, hasMore, loading],
  )

  return useMemo(() => ({ options, labelOf, search }), [options, labelOf, search])
}
