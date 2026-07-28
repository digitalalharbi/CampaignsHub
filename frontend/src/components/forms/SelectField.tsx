import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { Check, ChevronDown, Plus, Settings2 } from 'lucide-react'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'
import { FORMS_COPY, optionLabel, type BaseFieldProps, type Option } from './types'
import { useClickOutside, useListNavigation, useTypeahead } from './useTypeahead'
import {
  ClearButton,
  OptionSwatch,
  PanelSearch,
  PanelState,
  Spinner,
  panelClass,
  triggerClass,
} from './internals'

export interface SelectFieldProps extends BaseFieldProps {
  value: string | null
  onChange: (value: string | null) => void
  options: Option[]
  placeholder?: string
  /** Force the search box on/off. Defaults to on when there are more than 7 options. */
  searchable?: boolean
  /** Show the clear (×) button when a value is set. Default true. */
  clearable?: boolean
  /** Async/loader states — AsyncSelect drives these; local selects can ignore them. */
  loading?: boolean
  optionsError?: string | null
  onRetry?: () => void
  /** Called on every search keystroke (server search hook). */
  onSearchChange?: (query: string) => void
  /** Renders a "+ add …" row at the foot of the list when true. */
  canCreate?: boolean
  onCreate?: (query: string) => void
  /** Renders a gear that opens option management without leaving the form. */
  onManage?: () => void
  emptyText?: string
}

/**
 * Unified single-select: searchable, keyboard-navigable (↑↓/Enter/Esc), clearable, with
 * loading/empty/error states, disabled options, and optional create/manage affordances.
 * Fully controlled and RTL-correct.
 */
export function SelectField({
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
  placeholder,
  searchable,
  clearable = true,
  loading,
  optionsError,
  onRetry,
  onSearchChange,
  canCreate,
  onCreate,
  onManage,
  emptyText,
}: SelectFieldProps) {
  const locale = useUi((s) => s.locale)
  const copy = FORMS_COPY[locale]
  const reactId = useId()
  const fieldId = id ?? reactId
  const listId = `${fieldId}-listbox`

  const [open, setOpen] = useState(false)
  const { query, setQuery, filtered } = useTypeahead(options, locale)
  const searchInputRef = useRef<HTMLInputElement>(null)

  const isSearchable = searchable ?? (options.length > 7 || Boolean(onSearchChange))
  const selected = useMemo(() => options.find((o) => o.value === value) ?? null, [options, value])

  // The create row (if shown) sits after the options and is the last navigable index.
  const showCreateRow = Boolean(canCreate && onCreate && query.trim().length > 0)
  const rowCount = filtered.length + (showCreateRow ? 1 : 0)

  const close = () => {
    setOpen(false)
    setQuery('')
  }
  const containerRef = useClickOutside<HTMLDivElement>(open, close)

  const selectIndex = (index: number) => {
    if (index < filtered.length) {
      const opt = filtered[index]
      if (opt.disabled) return
      onChange(opt.value)
      close()
    } else if (showCreateRow) {
      onCreate?.(query.trim())
      close()
    }
  }

  const { active, setActive, handleKeyDown } = useListNavigation({
    isOpen: open,
    count: rowCount,
    onSelect: selectIndex,
    onClose: close,
    onOpen: () => setOpen(true),
    isDisabled: (i) => i < filtered.length && Boolean(filtered[i].disabled),
  })

  const openPanel = () => {
    if (disabled) return
    setOpen(true)
    if (onSearchChange) onSearchChange('')
    // Focus the search box on the next frame so keyboard users land inside it.
    requestAnimationFrame(() => searchInputRef.current?.focus())
  }

  const onTriggerKeyDown = (e: KeyboardEvent) => {
    // When closed, let the nav hook open on ArrowDown/Enter/Space.
    if (!open) handleKeyDown(e)
  }

  const onSearchKeyDown = (e: KeyboardEvent) => handleKeyDown(e)

  const handleSearch = (v: string) => {
    setQuery(v)
    onSearchChange?.(v)
  }

  return (
    <Field label={label} htmlFor={fieldId} hint={hint} error={error} required={required}>
      <div ref={containerRef} className={`relative ${className}`}>
        <button
          type="button"
          id={fieldId}
          role="combobox"
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-controls={listId}
          aria-invalid={error ? true : undefined}
          disabled={disabled}
          onClick={() => (open ? close() : openPanel())}
          onKeyDown={onTriggerKeyDown}
          className={`${triggerClass} w-full`}
        >
          {selected && <OptionSwatch option={selected} />}
          <span className={`flex-1 truncate ${selected ? 'text-text-primary' : 'text-text-muted'}`}>
            {selected ? optionLabel(selected, locale) : placeholder ?? copy.placeholder}
          </span>
          {loading && <Spinner />}
          {clearable && selected && !disabled && (
            <ClearButton label={copy.clear} onClear={() => onChange(null)} />
          )}
          <ChevronDown size={16} aria-hidden className={`shrink-0 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
        </button>

        {name && <input type="hidden" name={name} value={value ?? ''} />}

        {open && (
          <div className={panelClass}>
            {isSearchable && (
              <PanelSearch
                ref={searchInputRef}
                value={query}
                onChange={handleSearch}
                onKeyDown={onSearchKeyDown}
                placeholder={copy.searchPlaceholder}
                ariaControls={listId}
                ariaActiveDescendant={rowCount > 0 ? `${listId}-opt-${active}` : undefined}
              />
            )}

            <PanelState
              loading={loading}
              error={optionsError}
              isEmpty={filtered.length === 0 && !showCreateRow}
              copy={copy}
              onRetry={onRetry}
            />

            {!loading && !optionsError && (filtered.length > 0 || showCreateRow) && (
              <ul id={listId} role="listbox" aria-label={label} className="max-h-64 overflow-y-auto py-1">
                {filtered.length === 0 && !showCreateRow && (
                  <li className="px-3 py-6 text-center text-sm text-text-muted">{emptyText ?? copy.empty}</li>
                )}
                {filtered.map((opt, i) => {
                  const isSelected = opt.value === value
                  const isActive = i === active
                  return (
                    <li
                      key={opt.value}
                      id={`${listId}-opt-${i}`}
                      role="option"
                      aria-selected={isSelected}
                      aria-disabled={opt.disabled || undefined}
                      onMouseEnter={() => !opt.disabled && setActive(i)}
                      onMouseDown={(e) => {
                        e.preventDefault()
                        selectIndex(i)
                      }}
                      className={`flex cursor-pointer items-center gap-2 px-3 py-2 text-sm ${
                        opt.disabled
                          ? 'cursor-not-allowed text-text-muted opacity-60'
                          : isActive
                            ? 'bg-surface-hover text-text-primary'
                            : 'text-text-secondary'
                      }`}
                    >
                      <OptionSwatch option={opt} />
                      <span className="flex-1 truncate">
                        {optionLabel(opt, locale)}
                        {opt.description && (
                          <span className="block truncate text-xs text-text-muted">{opt.description}</span>
                        )}
                      </span>
                      {isSelected && <Check size={15} aria-hidden className="shrink-0 text-brand-600" />}
                    </li>
                  )
                })}

                {showCreateRow && (
                  <li
                    id={`${listId}-opt-${filtered.length}`}
                    role="option"
                    aria-selected={false}
                    onMouseEnter={() => setActive(filtered.length)}
                    onMouseDown={(e) => {
                      e.preventDefault()
                      selectIndex(filtered.length)
                    }}
                    className={`flex cursor-pointer items-center gap-2 border-t border-border px-3 py-2 text-sm font-semibold ${
                      active === filtered.length ? 'bg-surface-hover text-brand-700' : 'text-brand-600'
                    }`}
                  >
                    <Plus size={15} aria-hidden /> {copy.add} “{query.trim()}”
                  </li>
                )}
              </ul>
            )}

            {onManage && (
              <button
                type="button"
                onMouseDown={(e) => {
                  e.preventDefault()
                  close()
                  onManage()
                }}
                className="flex items-center gap-2 border-t border-border px-3 py-2 text-start text-xs font-semibold text-text-secondary hover:bg-surface-hover hover:text-text-primary"
              >
                <Settings2 size={14} aria-hidden /> {copy.manage}
              </button>
            )}
          </div>
        )}
      </div>
    </Field>
  )
}
