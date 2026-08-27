import { useAuth } from '@/stores/auth'

/**
 * NUMERAL-PREFERENCE-001 — one place decides what a digit looks like.
 *
 * ## The gap
 *
 * `number_format` is a real column with a real default (`latin`), validated by `MeController` and
 * `OrganizationSettingsController`, migrated, returned by `UserResource`, and offered in Settings as
 * «أرقام عربية (١٢٣)». Nothing read it. Every formatter in the product hardcoded `'en-US'`, so a
 * person could choose Arabic digits, see the choice saved, and watch nothing change — the same shape
 * as an alert rule that saves and can never fire.
 *
 * ## The rule this does NOT break
 *
 * «Latin digits in both languages» is documented in twelve places and asserted by two tests. Read
 * carefully, it is a statement about LANGUAGE: choosing Arabic must not drag the numerals with it,
 * because «١٢٬٤٠٠ ريال» beside an English platform name is unreadable and an Arabic screenshot has
 * to stay comparable with the English one. It is not a statement that a person may never ask for
 * Arabic numerals on purpose.
 *
 * So the language axis and the preference axis stay separate: `locale === 'ar'` still formats in
 * Latin digits, which is what those tests assert and why they still pass. Only an explicit
 * `number_format: 'arabic'` changes anything.
 *
 * ## Where this deliberately does not reach
 *
 * - **Emails.** Rendered server-side, and `DigestPresenter` states the reason: a screenshot of the
 *   Arabic digest has to be comparable with the English one. They stay Latin.
 * - **Client shared reports.** The viewer is not signed in and has expressed no preference, so
 *   `user` is null and the default applies. A client must never be shown numerals their agency
 *   happened to choose.
 * - **Machine-readable values** — ids, ISO dates, currency codes, anything copied or parsed. Those
 *   are not prose and never route through here.
 */
export type NumeralSystem = 'latin' | 'arabic'

/** The BCP-47 locale each system formats under. `-u-nu-` names the numbering system explicitly. */
const LOCALE: Record<NumeralSystem, string> = {
  latin: 'en-US',
  // Egyptian Arabic carries the Arabic-Indic digits AND the Arabic group separator, which is what
  // «أرقام عربية (١٢٣)» promises. `-u-nu-arab` states it rather than relying on a regional default.
  arabic: 'ar-EG-u-nu-arab',
}

/**
 * The signed-in person's choice, or the product default.
 *
 * Read from the store rather than a hook so the pure formatters in `analytics/format.ts` can call it
 * — they are ordinary functions used inside `useMemo`, table cells and chart tick callbacks, and
 * making them hooks would be a far larger change than the one this fixes. `getState()` is the
 * documented way to read a zustand store outside React.
 *
 * Absent user ⇒ `latin`. That covers the marketing site, the login page and the client shared report,
 * none of which has a person whose preference could apply.
 */
export function numeralSystem(): NumeralSystem {
  return useAuth.getState().user?.number_format === 'arabic' ? 'arabic' : 'latin'
}

/** The locale to format under, for a given system or the current one. */
export function numeralLocale(system: NumeralSystem = numeralSystem()): string {
  return LOCALE[system] ?? LOCALE.latin
}

/**
 * Format a number under the current numeral system.
 *
 * One entry point, deliberately: sixty-eight call sites were building their own `Intl.NumberFormat`
 * with a hardcoded locale, which is exactly how a preference ends up honoured in four places and
 * ignored in sixty-four.
 */
export function formatNumber(
  value: number,
  options: Intl.NumberFormatOptions = {},
  system: NumeralSystem = numeralSystem(),
): string {
  return new Intl.NumberFormat(numeralLocale(system), options).format(value)
}

/**
 * A fixed number of decimals, in the current numeral system.
 *
 * `toFixed` always emits Latin digits, so every `toFixed` on a user-visible number was a silent
 * override of the preference. This is its replacement.
 */
export function formatFixed(
  value: number,
  digits: number,
  system: NumeralSystem = numeralSystem(),
): string {
  return formatNumber(value, { minimumFractionDigits: digits, maximumFractionDigits: digits }, system)
}
