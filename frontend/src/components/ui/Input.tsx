import { forwardRef, type InputHTMLAttributes } from 'react'
import { controlClass } from './Field'

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { invalid, className = '', ...rest },
  ref,
) {
  return (
    <input
      ref={ref}
      aria-invalid={invalid || undefined}
      className={`${controlClass} ${className}`}
      {...rest}
    />
  )
})
