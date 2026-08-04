import { useId } from 'react'
import { controlClass } from './Field'
import { normalisePhone } from '@/lib/phone'

/**
 * PHONE-SA-001 — one phone control, Saudi by default.
 *
 * ## Why a dial-code select and not a free-text field
 *
 * Every form here used a plain text input, so a Saudi customer typing `0501234567` — the way they
 * write their own number to everybody else — was relying on the server guessing their country. The
 * server does guess, correctly, and that is not the point: the customer could not SEE that it had,
 * and the ones who did not trust it typed `+966` themselves and got `+9660501234567` back, which is
 * not a number that exists anywhere.
 *
 * So the country is a control with a visible value, `+966` is what it opens on, and the field beside
 * it is for the national number. Choosing another country changes the code and nothing else.
 *
 * ## What it still accepts
 *
 * Everything the server accepts, because a control that refused what the backend takes would be a
 * second, stricter rule with no reason to exist. `0501234567`, `501234567`, `966501234567`,
 * `+966501234567`, spaces, dashes, and Arabic-Indic digits all resolve to the same E.164 value. If
 * what the customer types already carries a country code, THAT code wins over the dropdown — pasting
 * a full international number should not be silently re-homed to Saudi Arabia.
 *
 * `onChange` emits the raw text so the field never fights the person typing; `value()` and the form
 * around it read `normalisePhone` when they need the canonical form. Normalising on every keystroke
 * would rewrite the input under the cursor, which is how a phone field becomes unusable.
 */

/** The dial codes this product actually sees, Saudi Arabia first because that is the home market. */
export const DIAL_CODES = [
  { code: '966', ar: 'السعودية', en: 'Saudi Arabia', flag: '🇸🇦' },
  { code: '971', ar: 'الإمارات', en: 'United Arab Emirates', flag: '🇦🇪' },
  { code: '965', ar: 'الكويت', en: 'Kuwait', flag: '🇰🇼' },
  { code: '973', ar: 'البحرين', en: 'Bahrain', flag: '🇧🇭' },
  { code: '974', ar: 'قطر', en: 'Qatar', flag: '🇶🇦' },
  { code: '968', ar: 'عُمان', en: 'Oman', flag: '🇴🇲' },
  { code: '962', ar: 'الأردن', en: 'Jordan', flag: '🇯🇴' },
  { code: '20', ar: 'مصر', en: 'Egypt', flag: '🇪🇬' },
  { code: '961', ar: 'لبنان', en: 'Lebanon', flag: '🇱🇧' },
  { code: '964', ar: 'العراق', en: 'Iraq', flag: '🇮🇶' },
  { code: '212', ar: 'المغرب', en: 'Morocco', flag: '🇲🇦' },
  { code: '90', ar: 'تركيا', en: 'Türkiye', flag: '🇹🇷' },
  { code: '44', ar: 'المملكة المتحدة', en: 'United Kingdom', flag: '🇬🇧' },
  { code: '1', ar: 'الولايات المتحدة', en: 'United States', flag: '🇺🇸' },
] as const

export const DEFAULT_DIAL_CODE = '966'

export interface PhoneFieldProps {
  id?: string
  label: string
  /** The national number as typed. Never normalised while the person is still typing. */
  value: string
  onChange: (value: string) => void
  /** The selected dial code, without a `+`. */
  dialCode: string
  onDialCodeChange: (code: string) => void
  ar: boolean
  error?: string
  hint?: string
  required?: boolean
  autoFocus?: boolean
  autoComplete?: string
}

/**
 * The canonical E.164 value for what is currently in the control, or null if it is not readable yet.
 *
 * The dial code is used as the DEFAULT country, not as a prefix that is glued on: a value that
 * already names a country keeps it, which is what makes pasting `+201234567890` work while the
 * dropdown still says Saudi Arabia.
 */
export function phoneFieldValue(value: string, dialCode: string): string | null {
  return normalisePhone(value, dialCode)
}

export function PhoneField({
  id, label, value, onChange, dialCode, onDialCodeChange, ar,
  error, hint, required, autoFocus, autoComplete = 'tel',
}: PhoneFieldProps) {
  const auto = useId()
  const fieldId = id ?? auto

  return (
    <div className="block">
      <label htmlFor={fieldId} className="mb-1.5 block text-sm font-semibold text-text-secondary">
        {label}
        {required && <span className="text-danger"> *</span>}
      </label>

      {/*
        `dir="ltr"` on the row, in both languages.

        A phone number is read left-to-right everywhere on earth, and an Arabic page that mirrors it
        puts the dial code on the right of the digits — which reads as a different number. The LABEL
        above stays in the page's direction; only the number itself is pinned.
      */}
      <div dir="ltr" className="flex items-stretch gap-2">
        {/*
          The width lives on a WRAPPER, not on the control.

          `controlClass` opens with `w-full`, and Tailwind resolves two width utilities by stylesheet
          order rather than by the order they appear in the class attribute — so `w-[7.5rem]` beside
          it did not reliably win, and the dial-code select swallowed the row while the number field
          shrank to a stub. A wrapper of a fixed width with a `w-full` control inside cannot be
          ambiguous.
        */}
        <div className="w-[7.5rem] shrink-0">
          <select
            data-testid={`${fieldId}-dial-code`}
            aria-label={ar ? 'مفتاح الدولة' : 'Country dialling code'}
            value={dialCode}
            onChange={(e) => onDialCodeChange(e.target.value)}
            className={`${controlClass} px-2 text-sm`}
          >
            {DIAL_CODES.map((c) => (
              <option key={c.code} value={c.code}>
                {c.flag} +{c.code}
              </option>
            ))}
          </select>
        </div>

        <div className="min-w-0 flex-1">
          <input
            id={fieldId}
            data-testid={fieldId}
            type="tel"
            inputMode="tel"
            autoComplete={autoComplete}
            autoFocus={autoFocus}
            required={required}
            aria-invalid={error ? true : undefined}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            // The national form, because that is what somebody in this market will type. Not an
            // instruction — a demonstration that the country code is already handled.
            placeholder={dialCode === DEFAULT_DIAL_CODE ? '05x xxx xxxx' : ''}
            className={controlClass}
          />
        </div>
      </div>

      {error ? (
        <span className="mt-1.5 block text-[13px] text-danger" role="alert">{error}</span>
      ) : hint ? (
        <span className="mt-1.5 block text-[13px] text-text-muted">{hint}</span>
      ) : null}
    </div>
  )
}
