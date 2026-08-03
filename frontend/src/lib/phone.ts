/**
 * PHONE-001 — the browser's half of one reading of a phone number.
 *
 * ## Why this exists at all, given the backend already normalises
 *
 * Because the form is where the customer is REFUSED. The intake page tested `/^\+[1-9]\d{7,14}$/`
 * against what they typed, so `0501234567` — the way almost every Saudi customer writes their own
 * number — never reached the server to be normalised: it was rejected in the browser with a message
 * telling them to add a country code they had no reason to think was missing. Relaxing the backend
 * alone would have changed nothing they could see.
 *
 * ## This is not the source of truth
 *
 * The backend is. `App\Support\PhoneNumber` decides what gets stored and what duplicates what; this
 * exists so the form accepts the same shapes and can show the number back the way it will be saved.
 * Deliberately kept small and deliberately not enforcing anything the server doesn't — a client-side
 * rule stricter than the server's is a rule nobody can see or override, and that is the defect being
 * removed here.
 */

/** Saudi Arabia — this product's home market, and the assumption when no country is given. */
const DEFAULT_COUNTRY = '966'

/** Longest first, or `+971` is read as `+97` with a stray `1`. Mirrors the backend's list. */
const KNOWN_CODES = [
  '966', '971', '965', '973', '974', '968', '962', '961', '963', '964',
  '212', '216', '213', '249', '218', '967', '970', '20', '90', '44',
  '49', '33', '39', '34', '91', '92', '1',
]

/** Arabic-Indic and Persian digits are digits — the numerals most of these customers type. */
const toAsciiDigits = (value: string) =>
  value.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
    .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))

/**
 * Normalise to E.164, or null if there is not a plausible number in there.
 *
 * Same rules as the backend: separators are noise, `00` is `+`, a leading zero is a national dialling
 * prefix, a stated country code is kept, and no country code means the default region.
 */
export function normalisePhone(input: string | null | undefined, defaultCountry = DEFAULT_COUNTRY): string | null {
  if (input === null || input === undefined) return null

  const value = toAsciiDigits(input.trim())
  if (value === '') return null

  const explicit = value.startsWith('+') || value.replace(/\D+/g, '').startsWith('00')
  let digits = value.replace(/\D+/g, '')
  if (digits === '') return null
  if (digits.startsWith('00')) digits = digits.slice(2)

  const plausible = (d: string) => d.length >= 8 && d.length <= 15

  if (explicit) return plausible(digits) ? `+${digits}` : null

  for (const code of KNOWN_CODES) {
    if (digits.startsWith(code) && digits.length > code.length + 5) {
      const rest = digits.slice(code.length).replace(/^0+/, '')
      return plausible(code + rest) ? `+${code}${rest}` : null
    }
  }

  const national = digits.replace(/^0+/, '')
  return plausible(defaultCountry + national) ? `+${defaultCountry}${national}` : null
}

/** Whether the form should accept this. Empty is not invalid — `required` decides that separately. */
export const isReadablePhone = (input: string | null | undefined) =>
  input === null || input === undefined || input.trim() === '' || normalisePhone(input) !== null

/**
 * The error to show, in the reader's language, naming BOTH accepted shapes.
 *
 * «Enter a valid number» tells somebody staring at a number they believe is valid nothing at all.
 */
export const phoneError = (ar: boolean) =>
  ar
    ? 'أدخل رقم جوال صحيح، مثل 0501234567 أو ‎+966501234567'
    : 'Enter a valid mobile number, for example 0501234567 or +966501234567'
