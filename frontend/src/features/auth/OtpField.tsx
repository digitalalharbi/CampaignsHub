import { useEffect, useRef } from 'react'

/**
 * LOGIN-OTP-001 — six digits, six boxes.
 *
 * ## Why boxes and not one field
 *
 * A code arrives as six characters read off a phone and typed with the eyes somewhere else. Six
 * boxes give the one thing a single input cannot: a visible position, so somebody who looks back at
 * the screen after glancing at their phone can see how far they got without counting characters.
 *
 * ## The three things that actually break OTP inputs
 *
 * 1. **Paste.** Most people paste the whole code, and a per-box `maxLength=1` throws away five of
 *    the six characters. Every box accepts a full paste and distributes it.
 * 2. **Backspace on an empty box.** Without handling it, correcting a mistake means clicking back a
 *    box by hand. Backspace in an empty box moves to the previous one and clears it.
 * 3. **Digits that are not Latin.** An Arabic keyboard produces `٠١٢٣٤٥٦٧٨٩`, which no comparison
 *    on the server will match. They are folded to Latin on the way in, which is the platform rule
 *    everywhere else too.
 *
 * `dir="ltr"` on the row, in both languages: a code is a number read left to right, and mirroring it
 * in Arabic would put the first digit typed at the right of the row and the caret travelling the
 * wrong way.
 */

const LENGTH = 6

/** `٤` and `۴` are both four. Anything that is not a digit at all is dropped. */
function toLatinDigits(input: string): string {
  return [...input]
    .map((ch) => {
      const code = ch.codePointAt(0) ?? 0
      if (code >= 0x0660 && code <= 0x0669) return String(code - 0x0660) // Arabic-Indic
      if (code >= 0x06f0 && code <= 0x06f9) return String(code - 0x06f0) // Extended Arabic-Indic
      return ch
    })
    .filter((ch) => ch >= '0' && ch <= '9')
    .join('')
}

export function OtpField({
  value,
  onChange,
  onComplete,
  label,
  autoFocus = false,
  disabled = false,
}: {
  value: string
  onChange: (next: string) => void
  /** Fired once the sixth digit lands, so the code need not also be submitted by hand. */
  onComplete?: (code: string) => void
  label: string
  autoFocus?: boolean
  disabled?: boolean
}) {
  const boxes = useRef<Array<HTMLInputElement | null>>([])
  const digits = value.padEnd(LENGTH, ' ').slice(0, LENGTH).split('')

  useEffect(() => {
    if (autoFocus) boxes.current[0]?.focus()
  }, [autoFocus])

  const put = (raw: string, from: number) => {
    const typed = toLatinDigits(raw)
    if (typed === '') return

    const next = (value.slice(0, from) + typed + value.slice(from + typed.length))
      .slice(0, LENGTH)

    onChange(next)

    const landed = Math.min(from + typed.length, LENGTH - 1)
    boxes.current[landed]?.focus()

    if (next.length === LENGTH) onComplete?.(next)
  }

  return (
    <div>
      <span className="mb-1.5 block text-sm font-semibold text-text-secondary">{label}</span>
      <div data-testid="login-otp" dir="ltr" className="flex items-center justify-center gap-2">
        {digits.map((digit, i) => (
          <input
            key={i}
            ref={(el) => { boxes.current[i] = el }}
            data-testid={`login-otp-${i}`}
            aria-label={`${label} ${i + 1}`}
            inputMode="numeric"
            autoComplete={i === 0 ? 'one-time-code' : 'off'}
            disabled={disabled}
            value={digit.trim()}
            onChange={(e) => put(e.target.value, i)}
            onPaste={(e) => { e.preventDefault(); put(e.clipboardData.getData('text'), 0) }}
            onKeyDown={(e) => {
              if (e.key !== 'Backspace') return
              e.preventDefault()

              if (value[i]) {
                onChange(value.slice(0, i) + value.slice(i + 1))
                return
              }

              const prev = Math.max(0, i - 1)
              onChange(value.slice(0, prev) + value.slice(prev + 1))
              boxes.current[prev]?.focus()
            }}
            className="h-12 w-11 rounded-xl border border-border bg-surface text-center font-mono text-lg font-semibold text-text-primary outline-none transition-colors focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 disabled:opacity-60 sm:h-[52px] sm:w-12"
          />
        ))}
      </div>
    </div>
  )
}
