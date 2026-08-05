import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'

/**
 * The states one of the six ad platforms can honestly be in, as a TENANT sees them (INTEG-UI-001).
 *
 * `disconnected` is not a failure: the platform is configured and simply nobody has authorised it
 * yet. Collapsing it into `awaiting_credentials` — the shape this page had before — told an operator
 * to go and find keys that were already provisioned.
 *
 * `unavailable` (PROVCFG-001) is the provider the platform operator has taken out of service. It is
 * kept apart from `awaiting_credentials` in the DATA even though the page says the same sentence for
 * both, because they are different facts and a future surface may need to tell them apart — one is
 * a setup that has not happened, the other a decision that has.
 */
export type PlatformState =
  | 'connected' | 'syncing' | 'error' | 'awaiting_credentials' | 'unavailable' | 'disconnected'

export interface Connector {
  key: string
  label: string
  status: 'awaiting_credentials' | 'connected' | 'error' | 'disconnected'
  ad_account_id: string | null
  last_synced_at: string | null
  last_sync_error: string | null
  /** Present only for the six ad platforms; the sandbox and analytics connectors keep the old shape. */
  is_ad_platform?: boolean
  state?: PlatformState
  /*
   * There is deliberately no `missing` here any more. The list of absent SYSTEM credentials was being
   * served to tenants, and it is an instruction for `/admin` addressed to the wrong reader — see the
   * doc block on `IntegrationsPage`.
   */
  accounts?: number
  connection_error?: string | null
  token_expires_at?: string | null
  /** When DATA last arrived, which is the question a customer is actually asking. */
  data_last_synced_at?: string | null
}

export function listConnectors(): Promise<Connector[]> {
  return getData<Connector[]>('/integrations')
}

export async function connectConnector(key: string): Promise<{ key: string; status: string }> {
  await ensureCsrfCookie()
  return postData<{ key: string; status: string }>(`/integrations/${key}/connect`)
}

/**
 * Ask where to send the customer to authorise us.
 *
 * The URL is returned rather than followed by the server, because this call is a `fetch`: a 302 to
 * facebook.com would be followed by the fetch and swallowed, and the customer would sit on a page
 * where nothing happened. The caller navigates.
 */
/**
 * Begin the customer's own authorisation (CONNECT-001).
 *
 * `clientWorkspaceId` is the `→ Client` link in the chain the architecture requires:
 *
 *     system provider configuration → user OAuth consent → external account → client → project
 *
 * It travels inside the single-use `state`, never in the callback's query string, so the workspace an
 * account lands in is the one chosen HERE by an authenticated member — not one a returning browser
 * could name for itself.
 */
export async function startPlatformOAuth(
  provider: string,
  clientWorkspaceId?: string | null,
): Promise<{ authorization_url: string }> {
  await ensureCsrfCookie()
  return postData<{ authorization_url: string }>(
    `/integrations/${provider}/oauth/start`,
    clientWorkspaceId ? { client_workspace_id: clientWorkspaceId } : {},
  )
}

export async function syncConnector(key: string): Promise<{ success: boolean; count: number }> {
  await ensureCsrfCookie()
  return postData<{ success: boolean; count: number }>(`/integrations/${key}/sync`)
}
