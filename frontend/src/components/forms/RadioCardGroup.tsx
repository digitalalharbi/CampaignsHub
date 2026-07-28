import { useId, type ComponentType } from 'react'
import { Check } from 'lucide-react'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'
import { optionLabel, type BaseFieldProps, type Option } from './types'

export interface RadioCardOption extends Option {
  /** Optional lucide-style icon component rendered in the card. */
  Icon?: ComponentType<{ size?: number; className?: string }>
}

export interface RadioCardGroupProps extends Omit<BaseFieldProps, 'name'> {
  value: string | null
  onChange: (value: string) => void
  options: RadioCardOption[]
  name?: string
  /** Grid columns at md+ (default 2). */
  columns?: 1 | 2 | 3
}

const colClass: Record<1 | 2 | 3, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 sm:grid-cols-2',
  3: 'grid-cols-1 sm:grid-cols-2 md:grid-cols-3',
}

/**
 * Card-style single choice: each option is a selectable card (icon + label + description).
 * Rendered as a real radiogroup so arrow keys move focus and Space/Enter selects.
 */
export function RadioCardGroup({
  value,
  onChange,
  options,
  label,
  hint,
  error,
  required,
  disabled,
  id,
  name,
  className = '',
  columns = 2,
}: RadioCardGroupProps) {
  const locale = useUi((s) => s.locale)
  const reactId = useId()
  const groupName = name ?? id ?? reactId

  return (
    <Field label={label} hint={hint} error={error} required={required}>
      <div role="radiogroup" aria-label={label} aria-invalid={error ? true : undefined} className={`grid gap-3 ${colClass[columns]} ${className}`}>
        {options.map((opt) => {
          const isSelected = opt.value === value
          const isDisabled = disabled || opt.disabled
          const Icon = opt.Icon
          return (
            <label
              key={opt.value}
              className={`relative flex cursor-pointer items-start gap-3 rounded-[14px] border p-4 transition-colors ${
                isSelected ? 'border-brand-500 bg-brand-background' : 'border-border bg-surface hover:border-border-strong'
              } ${isDisabled ? 'cursor-not-allowed opacity-60' : ''}`}
            >
              <input
                type="radio"
                name={groupName}
                value={opt.value}
                checked={isSelected}
                disabled={isDisabled}
                onChange={() => {
                  if (!isDisabled) onChange(opt.value)
                }}
                className="sr-only"
              />
              {Icon ? (
                <span
                  className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] ${
                    isSelected ? 'bg-brand-600 text-white' : 'bg-surface-hover text-text-secondary'
                  }`}
                  aria-hidden
                >
                  <Icon size={18} />
                </span>
              ) : opt.color ? (
                <span className="mt-1 inline-block h-4 w-4 shrink-0 rounded-full" style={{ backgroundColor: opt.color }} aria-hidden />
              ) : null}
              <span className="flex flex-1 flex-col gap-0.5">
                <span className="text-sm font-semibold text-text-primary">{optionLabel(opt, locale)}</span>
                {opt.description && <span className="text-xs text-text-secondary">{opt.description}</span>}
              </span>
              <span
                className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border ${
                  isSelected ? 'border-brand-600 bg-brand-600 text-white' : 'border-border-strong'
                }`}
                aria-hidden
              >
                {isSelected && <Check size={13} />}
              </span>
            </label>
          )
        })}
      </div>
    </Field>
  )
}
