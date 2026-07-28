import { useId, useMemo } from 'react'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'
import { FORMS_COPY, optionLabel, type BaseFieldProps, type Option } from './types'

export interface CheckboxGroupProps extends BaseFieldProps {
  value: string[]
  onChange: (value: string[]) => void
  options: Option[]
  /** Show a Select all / Clear all toggle above the list. Default true. */
  selectAll?: boolean
  /** Layout columns (default 1). */
  columns?: 1 | 2 | 3
}

const colClass: Record<1 | 2 | 3, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 sm:grid-cols-2',
  3: 'grid-cols-1 sm:grid-cols-2 md:grid-cols-3',
}

/** Grouped checkbox set with an optional select-all. Controlled (value is an array). */
export function CheckboxGroup({
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
  selectAll = true,
  columns = 1,
}: CheckboxGroupProps) {
  const locale = useUi((s) => s.locale)
  const copy = FORMS_COPY[locale]
  const reactId = useId()
  const baseId = id ?? reactId

  const selectedSet = useMemo(() => new Set(value), [value])
  const pickable = useMemo(() => options.filter((o) => !o.disabled).map((o) => o.value), [options])
  const allSelected = pickable.length > 0 && pickable.every((v) => selectedSet.has(v))

  const toggle = (opt: Option) => {
    if (opt.disabled || disabled) return
    if (selectedSet.has(opt.value)) onChange(value.filter((v) => v !== opt.value))
    else onChange([...value, opt.value])
  }

  const toggleAll = () => {
    if (allSelected) onChange(value.filter((v) => !pickable.includes(v)))
    else onChange(Array.from(new Set([...value, ...pickable])))
  }

  return (
    <Field label={label} hint={hint} error={error} required={required}>
      <div className={className}>
        {selectAll && options.length > 1 && (
          <label className="mb-2 inline-flex cursor-pointer items-center gap-2 text-xs font-semibold text-brand-600">
            <input
              type="checkbox"
              checked={allSelected}
              disabled={disabled}
              onChange={toggleAll}
              className="h-4 w-4 rounded-[5px] border-border-strong accent-brand-600"
            />
            {allSelected ? copy.clearAll : copy.selectAll}
          </label>
        )}
        <div role="group" aria-label={label} className={`grid gap-2 ${colClass[columns]}`}>
          {options.map((opt) => {
            const cid = `${baseId}-${opt.value}`
            const isSelected = selectedSet.has(opt.value)
            return (
              <label
                key={opt.value}
                htmlFor={cid}
                className={`flex items-start gap-2 text-sm text-text-primary ${
                  opt.disabled || disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'
                }`}
              >
                <input
                  id={cid}
                  type="checkbox"
                  name={name ? `${name}[]` : undefined}
                  value={opt.value}
                  checked={isSelected}
                  disabled={opt.disabled || disabled}
                  onChange={() => toggle(opt)}
                  className="mt-0.5 h-4 w-4 shrink-0 rounded-[5px] border-border-strong accent-brand-600"
                />
                <span className="flex flex-col gap-0.5">
                  <span className="font-medium">{optionLabel(opt, locale)}</span>
                  {opt.description && <span className="text-xs text-text-secondary">{opt.description}</span>}
                </span>
              </label>
            )
          })}
        </div>
      </div>
    </Field>
  )
}
