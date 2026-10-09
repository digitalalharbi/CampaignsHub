import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'

/**
 * GA4-INTEGRATION-001 — the client's own Google Analytics 4 properties.
 *
 * The states are the SAME six the ad-platform and store boards use, on purpose: they are the same
 * six situations, and a customer moving between boards should not have to learn a third vocabulary
 * for «the platform operator has not finished setting this up».
 *
 * There is deliberately no `missing` field here either. Which system credential is absent is an
 * instruction for `/admin` addressed to the wrong reader.
 */
export type MeasurementState =
  | 'connected' | 'error' | 'awaiting_credentials' | 'unavailable' | 'disconnected'

export interface MeasurementProperty {
  id: string
  property_id: string
  name: string
  /** The Analytics account above it — a property reads as «Acme Group → Acme Store». */
  analytics_account_id: string | null
  analytics_account_name: string | null
  /**
   * Null until the first sync asked the property for its own settings.
   *
   * Discovery does not ask, so a never-synced property honestly says «not known yet». A guessed
   * `UTC` here would shift a Gulf client's whole report by a day, and a guessed currency would
   * label their revenue in the wrong money.
   */
  timezone: string | null
  currency: string | null
  /**
   * Whether somebody chose this property for a project.
   *
   * DISCOVERED ≠ SELECTED. An agency's Google identity commonly reaches dozens of clients'
   * properties; only a selected one is ever read.
   */
  is_selected: boolean
  project_id: string | null
  last_synced_at: string | null
  discovered_at: string | null
}

export interface MeasurementProvider {
  key: string
  label: string
  label_ar: string
  state: MeasurementState
  connection_error: string | null
  /** «2 of 17 properties» — the sentence that stops «all of them are being read». */
  discovered_count: number
  selected_count: number
  properties: MeasurementProperty[]
}

export function listMeasurementProviders(): Promise<MeasurementProvider[]> {
  return getData<MeasurementProvider[]>('/measurement/properties')
}

/**
 * Begin the customer's own authorisation.
 *
 * `clientWorkspaceId` travels inside the single-use `state` rather than in the callback's query
 * string — so the workspace a property lands in is the one chosen here by an authenticated member,
 * not one a returning browser could name for itself.
 */
export async function startMeasurementOAuth(
  provider: string,
  clientWorkspaceId?: string | null,
): Promise<{ authorization_url: string }> {
  await ensureCsrfCookie()
  return postData<{ authorization_url: string }>(
    `/integrations/measurement/${provider}/oauth/start`,
    clientWorkspaceId ? { client_workspace_id: clientWorkspaceId } : {},
  )
}

export interface MeasurementSyncResult {
  property_id: string
  project_id: string
  timezone: string
  from: string
  to: string
  /** Days the property returned — not days asked for; a new property has fewer. */
  days: number
  figures: number
}

export function syncMeasurementProperty(accountId: string, days?: number): Promise<MeasurementSyncResult> {
  return ensureCsrfCookie().then(() => postData<MeasurementSyncResult>(
    `/measurement/properties/${accountId}/sync`,
    days === undefined ? {} : { days },
  ))
}
