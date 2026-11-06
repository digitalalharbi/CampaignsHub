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

/**
 * INTEGRATIONS-CENTRE-001 §5 — the two SETS of accounts, named once.
 *
 * DISCOVERED is what an authorisation can see; SELECTED is what somebody chose for a project, and
 * only the second is read. The hub's counters, the wizard's picker and the estate's rows all state
 * these sets, and they used to do it in three phrasings — «الحسابات المختارة», «تم اختيار», «من … حساب»
 * — which is three chances to teach a reader that the discovered three hundred are being synced.
 * `connectionCopy.test.ts` holds every surface to this module.
 */
export const ACCOUNT_SET_COPY: Record<'discovered' | 'selected', { ar: string; en: string }> = {
  discovered: { ar: 'الحسابات المكتشفة', en: 'Discovered accounts' },
  selected: { ar: 'الحسابات المختارة', en: 'Selected accounts' },
}

/**
 * «3 of 40 selected» — the one sentence that relates the two sets, with Latin digits.
 *
 * Deliberately not a counted noun: the noun is the SET («selected»), and the number that matters to
 * a reader is the first one. The denominator is the discovered inventory on this connection, or on
 * this page of the picker, as the caller states.
 */
export function selectedOfDiscovered(selected: number, discovered: number, ar: boolean): string {
  const n = selected.toLocaleString('en-US')
  const m = discovered.toLocaleString('en-US')
  return ar ? `اختير ${n} من ${m}` : `${n} of ${m} selected`
}
