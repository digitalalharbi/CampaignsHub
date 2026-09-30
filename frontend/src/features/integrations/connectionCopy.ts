import type { ConnectionState, SyncState } from './api'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §16 — the two vocabularies, in one place, never merged.
 *
 * A chip is read in a second, so each word has to be the whole sentence. They live here rather than
 * in the components because the hub row, the drawer header and the flow's receipt all say the same
 * thing about the same connection, and three copies of a status word is three chances for two
 * surfaces to disagree about what a customer is looking at.
 */
export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

export const CONNECTION_COPY: Record<ConnectionState, { tone: Tone; ar: string; en: string }> = {
  NOT_CONNECTED: { tone: 'neutral', ar: 'غير مربوط', en: 'Not connected' },
  AWAITING_CREDENTIALS: { tone: 'neutral', ar: 'بانتظار التهيئة', en: 'Awaiting setup' },
  CONNECTED: { tone: 'success', ar: 'متصل', en: 'Connected' },
  REAUTH_REQUIRED: { tone: 'danger', ar: 'يحتاج إعادة مصادقة', en: 'Needs reconnecting' },
  REVOKED: { tone: 'danger', ar: 'أُلغيت المصادقة', en: 'Authorisation revoked' },
}

/**
 * `NEVER_SYNCED` is not a failure and is never dressed as one, and neither is a queue.
 *
 * «لم تبدأ المزامنة» over a connection authorised thirty seconds ago is the truth; a red chip there
 * would teach somebody to distrust a connection that is working exactly as designed.
 */
export const SYNC_COPY: Record<SyncState, { tone: Tone; ar: string; en: string }> = {
  NEVER_SYNCED: { tone: 'neutral', ar: 'لم تبدأ المزامنة', en: 'Never synced' },
  QUEUED: { tone: 'info', ar: 'المزامنة في الانتظار', en: 'Sync queued' },
  SYNCING: { tone: 'info', ar: 'المزامنة جارية', en: 'Syncing' },
  SUCCEEDED: { tone: 'success', ar: 'تمت المزامنة', en: 'Synced' },
  FAILED: { tone: 'danger', ar: 'تعذّرت المزامنة', en: 'Sync failed' },
}

/** Re-authorising is an ACT on the authorisation, so it outranks whatever the pipeline is doing. */
export function needsReauthorising(state: ConnectionState): boolean {
  return state === 'REAUTH_REQUIRED' || state === 'REVOKED'
}
