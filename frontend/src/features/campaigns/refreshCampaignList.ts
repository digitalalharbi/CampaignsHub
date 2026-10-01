import type { QueryClient } from '@tanstack/react-query'

/**
 * CAMPAIGN-LIST-REFRESH-001 — invalidate TWICE, because the first one can be absorbed.
 *
 * ## The bug this is named after
 *
 * A campaign somebody had just created could be missing from the list behind the dialog they
 * created it in — not slow to arrive, missing, and it stayed missing until the page was reloaded.
 *
 * The cause is a read that started before the campaign existed and finished after it. The campaigns
 * list is fetched with every filter in its key, so opening the page puts several reads in flight at
 * once; a reader who types a name and presses Save fast enough beats one of them home.
 * `invalidateQueries` on a query that is ALREADY fetching does not start a second request — it
 * adopts the one in flight as the refetch — so the response that lands is the one composed before
 * the POST, and it overwrites the cache with a list the new campaign is not in. Nothing is left
 * stale afterwards, so nothing ever fetches again.
 *
 * The second invalidation is what fixes it. `invalidateQueries` waits for the refetch it triggered,
 * so by the time the first call returns the absorbed read has landed and the query is idle — and an
 * invalidation of an idle query always starts a NEW request, which is therefore composed after the
 * write. One extra list fetch per created campaign, and the campaign is always in it.
 *
 * ## Why not `cancelQueries`
 *
 * Cancelling the stale reads first is the obvious fix and it was tried, in `onSuccess` and then in
 * `onMutate`. Both cured this bug and broke something worse: the SECOND campaign created in the
 * same session never sent its request at all, and its dialog came back carrying «Something went
 * wrong» on a field nobody had touched. Measured four times on chromium, and gone the moment the
 * cancel was removed. Invalidating twice costs one request and breaks nothing.
 *
 * ## How the original bug was found
 *
 * `campaigns.spec` and `campaigns-linking.spec` each failed the chromium gate on alternate runs,
 * always the same way: the entity was created, the API answered 201, and the name never appeared.
 * Reproduced locally at 1-in-3 and — the part that matters — reproduced on `main` at 2-in-4, so it
 * is a defect the product already had rather than one a branch introduced.
 */
export async function refreshCampaignList(client: QueryClient, projectId: string): Promise<void> {
  const key = ['project', projectId, 'campaigns']

  await client.invalidateQueries({ queryKey: key })
  await client.invalidateQueries({ queryKey: key })
}
