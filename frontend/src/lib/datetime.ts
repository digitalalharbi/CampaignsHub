/**
 * Canonical date/time formatting for the whole app.
 *
 * Product rule: dates are ALWAYS shown in the Gregorian calendar with Latin digits and a clear
 * ISO-like `YYYY-MM-DD` form, regardless of the UI language. `en-CA` gives Gregorian + Latin digits +
 * YYYY-MM-DD; `en-GB` time gives 24h HH:mm. Never use `toLocaleString(undefined, …)` for stored data —
 * under an Arabic locale it emits Arabic month names / AM-PM markers, which read as garbled ("طلاسم").
 */

/** `YYYY-MM-DD` (Gregorian, Latin digits) or `—` for empty/invalid. */
export function fmtDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('en-CA')
}

/**
 * `HH:mm:ss` (24h, Latin digits) or `—` — for a clock reading where only the time is the point.
 *
 * DATE-FORMAT-002 — three call sites used a bare `toLocaleTimeString()`, which takes the READER's
 * locale. Under Arabic that renders «3:40:04 م»: an Arabic AM/PM marker beside Latin digits, which
 * is the garbling this module's header warns about, on the page whose whole job is to state that
 * the system is healthy.
 */
export function fmtClock(value: Date | string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const d = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(d.getTime())) return '—'

  return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

/** `YYYY-MM-DD HH:mm` (Gregorian, Latin digits, 24h) or `—` for empty/invalid. */
export function fmtDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return `${d.toLocaleDateString('en-CA')} ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`
}
