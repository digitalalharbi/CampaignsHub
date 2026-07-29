import { forwardRef, type InputHTMLAttributes } from 'react'
import { controlClass } from './Field'

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean
}

// Native date/time inputs are localized by the browser at creation from the element's `lang`. Under an
// Arabic (RTL) page they otherwise show Arabic sub-field placeholders — pin them to Gregorian English
// (YYYY-MM-DD via en-CA) + LTR at creation so the calendar and value read clearly. See lib/dateInputs.ts.
const DATE_TYPES = new Set(['date', 'datetime-local', 'month', 'week', 'time'])

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { invalid, className = '', ...rest },
  ref,
) {
  const dateLike = typeof rest.type === 'string' && DATE_TYPES.has(rest.type)
  return (
    <input
      ref={ref}
      aria-invalid={invalid || undefined}
      className={`${controlClass} ${className}`}
      {...(dateLike ? { lang: 'en-CA', dir: 'ltr' } : {})}
      {...rest}
    />
  )
})
