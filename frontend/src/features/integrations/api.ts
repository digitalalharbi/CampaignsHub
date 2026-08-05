import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'

/**
 * The four states one of the six ad platforms can honestly be in (INTEG-UI-001).
 *
 * `disconnected` is the fifth and it is not a failure: the platform is configured and simply nobody
 * has authorised it yet. Collapsing it into `awaiting_credentials` — the shape this page had before —
 * told an operator to go and find keys that were already provisioned.
 */
export type PlatformState = 'connected' | 'syncing' | 'error' | 'awaiting_credentials' | 'disconnected'

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
  /** Which configured values are absent — what a setup page has to be told, not just "awaiting". */
  missing?: string[]
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
export async function startPlatformOAuth(provider: string): Promise<{ authorization_url: string }> {
  await ensureCsrfCookie()
  return postData<{ authorization_url: string }>(`/integrations/${provider}/oauth/start`)
}

export async function syncConnector(key: string): Promise<{ success: boolean; count: number }> {
  await ensureCsrfCookie()
  return postData<{ success: boolean; count: number }>(`/integrations/${key}/sync`)
}
