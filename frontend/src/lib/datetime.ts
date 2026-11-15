/**
 * Canonical date/time formatting for the whole app.
 *
 * Product rule: dates are ALWAYS shown in the Gregorian calendar with Latin digits and a clear
 * ISO-like `YYYY-MM-DD` form, regardless of the UI language. `en-CA` gives Gregorian + Latin digits +
 * YYYY-MM-DD; `en-GB` time gives 24h HH:mm. Never use `toLocaleString(undefined, …)` for stored data —
 * under an Arabic locale it emits Arabic month names / AM-PM markers, which read as garbled ("طلاسم").
 */

/**
 * NOV-SAFARI-001 — read a stored timestamp the way Safari does, not only the way Chrome does.
 *
 * Handing `Date` the spaced shape «2026-11-15 10:30:00» gives a valid date in Chrome and Firefox and an `Invalid Date` in Safari, whose
 * parser accepts only the ISO 8601 form with a `T` between the date and the time. A value from a log line, a mail
 * footer or a hand-built string in that spaced shape rendered as «—» on every iPhone and every Mac Safari while
 * looking fine on the developer's machine. This puts the `T` in before parsing, so the shared formatters below read
 * both. Anything else is handed to `Date` unchanged — it is not the place to guess at other formats.
 *
 * Code that builds a date from parts writes the `T` itself (`${day}T00:00:00`); `isoDateSafety.test.ts` fails the
 * build on the spaced shape and on slash dates.
 */
export function parseDate(value: string | number | Date): Date {
  if (typeof value !== 'string') return new Date(value)

  return new Date(value.trim().replace(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/, '$1T$2'))
}

/** `YYYY-MM-DD` (Gregorian, Latin digits) or `—` for empty/invalid. */
export function fmtDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = parseDate(iso)
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
  const d = value instanceof Date ? value : parseDate(value)
  if (Number.isNaN(d.getTime())) return '—'

  return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

/** `YYYY-MM-DD HH:mm` (Gregorian, Latin digits, 24h) or `—` for empty/invalid. */
export function fmtDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = parseDate(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return `${d.toLocaleDateString('en-CA')} ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`
}
