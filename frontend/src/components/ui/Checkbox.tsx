import { type InputHTMLAttributes } from 'react'

interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label?: string
}

export function Checkbox({ label, className = '', id, ...rest }: CheckboxProps) {
  return (
    <label htmlFor={id} className="inline-flex cursor-pointer items-center gap-2 text-[13px] text-text-primary">
      <input
        id={id}
        type="checkbox"
        className={`h-4 w-4 rounded-[5px] border-border-strong text-brand-600 accent-brand-600 ${className}`}
        {...rest}
      />
      {label}
    </label>
  )
}
