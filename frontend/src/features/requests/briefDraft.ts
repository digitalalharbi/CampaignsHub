/**
 * OPS-1 — the brief survives a closed tab.
 *
 * A client filling a long brief on a phone loses it to a call, a tab discard or a flat battery. The draft
 * is kept on THIS device only, for seven days, and never leaves it: uploaded files and the
 * contact-verification ids are not stored (a verification must be done in the session that submits).
 * Every storage call is guarded — private windows and blocked site data must leave the form working.
 */
const KEY = 'ch.briefDraft.v1'
const TTL_MS = 7 * 24 * 60 * 60 * 1000

export interface BriefDraft {
  selectedKeys: string[]
  answers: Record<string, unknown>
  customRequest: string
  serviceNotes: Record<string, string>
  applicant: { contact_name: string; contact_email: string; contact_phone: string; dial_code: string; company_name: string }
  currency: string
  notes: string
}

interface Stored {
  savedAt: number
  draft: BriefDraft
}

export function loadBriefDraft(now: number = Date.now()): BriefDraft | null {
  try {
    const raw = window.localStorage.getItem(KEY)
    if (!raw) return null
    const stored = JSON.parse(raw) as Stored
    if (typeof stored?.savedAt !== 'number' || now - stored.savedAt > TTL_MS || typeof stored.draft !== 'object' || stored.draft === null) {
      window.localStorage.removeItem(KEY)
      return null
    }
    return stored.draft
  } catch {
    return null
  }
}

export function saveBriefDraft(draft: BriefDraft, now: number = Date.now()): boolean {
  try {
    window.localStorage.setItem(KEY, JSON.stringify({ savedAt: now, draft } satisfies Stored))
    return true
  } catch {
    return false
  }
}

export function clearBriefDraft(): void {
  try {
    window.localStorage.removeItem(KEY)
  } catch {
    /* nothing to clear */
  }
}

/** A draft with nothing in it is not worth a «saved» badge. */
export function isBlankDraft(d: BriefDraft): boolean {
  return (
    d.selectedKeys.length === 0 &&
    Object.keys(d.answers).length === 0 &&
    d.customRequest.trim() === '' &&
    d.notes.trim() === '' &&
    d.applicant.contact_name.trim() === '' &&
    d.applicant.contact_email.trim() === '' &&
    d.applicant.contact_phone.trim() === '' &&
    d.applicant.company_name.trim() === ''
  )
}
