import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'

/**
 * COMMERCE-001 — the merchant's own Salla / Zid stores.
 *
 * The states are the SAME six the ad-platform board uses, on purpose: they are the same six
 * situations, and a customer moving between the two boards should not have to learn a second
 * vocabulary for «the platform operator has not finished setting this up».
 *
 * There is deliberately no `missing` field here either. Which system credential is absent is an
 * instruction for `/admin` addressed to the wrong reader.
 */
export type StoreState =
  | 'connected' | 'syncing' | 'error' | 'awaiting_credentials' | 'unavailable' | 'disconnected'

export interface StoreRow {
  id: string
  external_id: string
  name: string
  domain: string | null
  currency: string | null
  /** When data last arrived. Null on a store that has been discovered but never synced. */
  last_synced_at: string | null
  counts: { products: number; orders: number; abandoned_carts: number }
  last_run: { status: string; records: number; error: string | null; finished_at: string | null } | null
}

export interface StoreProvider {
  key: string
  label: string
  state: StoreState
  connection_error: string | null
  token_expires_at: string | null
  stores: StoreRow[]
  /**
   * Whether this provider reports abandoned carts at all.
   *
   * Zid does not publish them. Showing «0 سلة متروكة» for a Zid store would claim a perfect checkout
   * rate for a merchant who is losing carts every day, so the absence is a stated capability.
   */
  supports_abandoned_carts: boolean
}

export function listStoreProviders(): Promise<StoreProvider[]> {
  return getData<StoreProvider[]>('/commerce/stores')
}

/**
 * Begin the merchant's own authorisation.
 *
 * `clientWorkspaceId` is the `→ Client` link of the chain, and it travels inside the single-use
 * `state` rather than in the callback's query string — so the workspace a store lands in is the one
 * chosen here by an authenticated member, not one a returning browser could name for itself.
 */
export async function startStoreOAuth(
  provider: string,
  clientWorkspaceId?: string | null,
): Promise<{ authorization_url: string }> {
  await ensureCsrfCookie()
  return postData<{ authorization_url: string }>(
    `/integrations/commerce/${provider}/oauth/start`,
    clientWorkspaceId ? { client_workspace_id: clientWorkspaceId } : {},
  )
}

/** Queue a sync. The endpoint answers 202 — a worker does the reading afterwards. */
export async function syncStore(storeId: string): Promise<{ queued: number; window_start: string }> {
  await ensureCsrfCookie()
  return postData<{ queued: number; window_start: string }>(`/commerce/stores/${storeId}/sync`)
}
