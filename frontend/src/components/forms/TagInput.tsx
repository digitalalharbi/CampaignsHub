import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'
import { FORMS_COPY, type BaseFieldProps } from './types'
import { useClickOutside } from './useTypeahead'
import { Chip } from './internals'

/** Chip-holding control shell that mirrors the app input (min-h, radius, border, focus-within). */
const controlWrap =
  'flex min-h-[56px] w-full flex-wrap items-center gap-1.5 rounded-[11px] border border-border bg-surface ' +
  'px-3 py-2 transition-colors focus-within:border-brand-500 focus-within:ring-[3px] focus-within:ring-brand-500/15'

export interface TagInputProps extends BaseFieldProps {
  value: string[]
  onChange: (value: string[]) => void
  /** Optional suggestion pool shown as a filtered dropdown while typing. */
  suggestions?: string[]
  placeholder?: string
  /** Cap the number of tags. */
  max?: number
  /** Normalize a tag before it is committed (default: trim). Return '' to reject. */
  normalize?: (raw: string) => string
}

/**
 * Free-text tag entry with chips and an optional suggestion list. Commit with Enter or comma,
 * remove the last tag with Backspace on an empty field, or click a chip's ×. Fully controlled.
 */
export function TagInput({
  value,
  onChange,
  suggestions = [],
  label,
  hint,
  error,
  required,
  disabled,
  id,
  name,
  className = '',
  placeholder,
  max,
  normalize = (raw) => raw.trim(),
}: TagInputProps) {
  const locale = useUi((s) => s.locale)
  const copy = FORMS_COPY[locale]
  const reactId = useId()
  const fieldId = id ?? reactId
  const inputRef = useRef<HTMLInputElement>(null)

  const [text, setText] = useState('')
  const [open, setOpen] = useState(false)
  const atMax = max != null && value.length >= max

  const filteredSuggestions = useMemo(() => {
    const q = text.trim().toLowerCase()
    const chosen = new Set(value.map((v) => v.toLowerCase()))
    return suggestions.filter((s) => !chosen.has(s.toLowerCase()) && (!q || s.toLowerCase().includes(q)))
  }, [suggestions, value, text])

  const close = () => setOpen(false)
  const containerRef = useClickOutside<HTMLDivElement>(open, close)

  const addTag = (raw: string) => {
    const tag = normalize(raw)
    if (!tag || atMax) return
    if (value.some((v) => v.toLowerCase() === tag.toLowerCase())) {
      setText('')
      return
    }
    onChange([...value, tag])
    setText('')
  }

  const removeAt = (index: number) => onChange(value.filter((_, i) => i !== index))

  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault()
      if (text.trim()) addTag(text)
    } else if (e.key === 'Backspace' && text === '' && value.length > 0) {
      removeAt(value.length - 1)
    } else if (e.key === 'Escape') {
      close()
    }
  }

  return (
    <Field label={label} htmlFor={fieldId} hint={hint} error={error} required={required}>
      <div ref={containerRef} className={`relative ${className}`}>
        <div
          className={controlWrap}
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) inputRef.current?.focus()
          }}
        >
          {value.map((tag, i) => (
            <Chip key={`${tag}-${i}`} removeLabel={copy.remove} disabled={disabled} onRemove={() => removeAt(i)}>
              {tag}
            </Chip>
          ))}
          <input
            ref={inputRef}
            id={fieldId}
            type="text"
            role="combobox"
            aria-expanded={open && filteredSuggestions.length > 0}
            aria-invalid={error ? true : undefined}
            disabled={disabled || atMax}
            value={text}
            placeholder={value.length === 0 ? placeholder ?? copy.tagPlaceholder : ''}
            onChange={(e) => {
              setText(e.target.value)
              setOpen(true)
            }}
            onFocus={() => setOpen(true)}
            onKeyDown={onKeyDown}
            onBlur={() => text.trim() && addTag(text)}
            className="min-w-[8ch] flex-1 bg-transparent py-1 text-sm text-text-primary outline-none placeholder:text-text-muted disabled:cursor-not-allowed"
          />
        </div>

        {name && value.map((v) => <input key={v} type="hidden" name={`${name}[]`} value={v} />)}

        {open && filteredSuggestions.length > 0 && !atMax && (
          <ul
            role="listbox"
            aria-label={copy.suggestions}
            className="absolute inset-x-0 top-full z-50 mt-1.5 max-h-56 overflow-y-auto rounded-[12px] border border-border bg-surface py-1 shadow-[var(--shadow-large)]"
          >
            {filteredSuggestions.map((s) => (
              <li
                key={s}
                role="option"
                aria-selected={false}
                onMouseDown={(e) => {
                  e.preventDefault()
                  addTag(s)
                  inputRef.current?.focus()
                }}
                className="cursor-pointer px-3 py-2 text-sm text-text-secondary hover:bg-surface-hover hover:text-text-primary"
              >
                {s}
              </li>
            ))}
          </ul>
        )}
      </div>
    </Field>
  )
}
