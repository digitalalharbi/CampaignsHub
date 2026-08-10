import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { Check, ChevronDown, ChevronUp, GripVertical, Plus, Settings2 } from 'lucide-react'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'
import { FORMS_COPY, optionLabel, type BaseFieldProps, type Option } from './types'
import { useClickOutside, useListNavigation, useTypeahead } from './useTypeahead'
import { Chip, OptionSwatch, PanelSearch, PanelState, Spinner, panelClass, triggerClass } from './internals'

export interface MultiSelectFieldProps extends BaseFieldProps {
  value: string[]
  onChange: (value: string[]) => void
  options: Option[]
  placeholder?: string
  searchable?: boolean
  loading?: boolean
  /** A sentence this caller owns, or the raw error — classified by `PanelState`. */
  optionsError?: unknown
  onRetry?: () => void
  onSearchChange?: (query: string) => void
  /** Cap the number of selections; the list disables further picks once reached. */
  maxSelections?: number
  /** Show Select all / Clear all controls. Default true. */
  bulkActions?: boolean
  /** Allow reordering the selected chips (up/down buttons + drag). Default false. */
  reorderable?: boolean
  canCreate?: boolean
  onCreate?: (query: string) => void
  onManage?: () => void
  emptyText?: string
}

/**
 * Unified multi-select: search, Select all / Clear all, removable chips, maxSelections,
 * reorderable selection, grouped options, and an optional create affordance. Controlled
 * (value is an ordered array). Dependent-options ready — the parent recomputes `options`.
 */
export function MultiSelectField({
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
  loading,
  optionsError,
  onRetry,
  onSearchChange,
  maxSelections,
  bulkActions = true,
  reorderable = false,
  canCreate,
  onCreate,
  onManage,
  emptyText,
}: MultiSelectFieldProps) {
  const locale = useUi((s) => s.locale)
  const copy = FORMS_COPY[locale]
  const reactId = useId()
  const fieldId = id ?? reactId
  const listId = `${fieldId}-listbox`

  const [open, setOpen] = useState(false)
  const { query, setQuery, filtered } = useTypeahead(options, locale)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const dragIndex = useRef<number | null>(null)

  const isSearchable = searchable ?? (options.length > 7 || Boolean(onSearchChange))
  const selectedSet = useMemo(() => new Set(value), [value])
  const atMax = maxSelections != null && value.length >= maxSelections

  // Preserve selection order for the chips row (value order wins over options order).
  const selectedOptions = useMemo(
    () => value.map((v) => options.find((o) => o.value === v)).filter((o): o is Option => Boolean(o)),
    [value, options],
  )

  // Group the filtered options; ungrouped options keep a null bucket rendered first.
  const groups = useMemo(() => {
    const map = new Map<string | null, Option[]>()
    for (const o of filtered) {
      const key = o.group ?? null
      if (!map.has(key)) map.set(key, [])
      map.get(key)!.push(o)
    }
    return Array.from(map.entries())
  }, [filtered])

  const showCreateRow = Boolean(canCreate && onCreate && query.trim().length > 0)
  const rowCount = filtered.length + (showCreateRow ? 1 : 0)

  const close = () => {
    setOpen(false)
    setQuery('')
  }
  const containerRef = useClickOutside<HTMLDivElement>(open, close)

  const toggle = (opt: Option) => {
    if (opt.disabled) return
    if (selectedSet.has(opt.value)) {
      onChange(value.filter((v) => v !== opt.value))
    } else {
      if (atMax) return
      onChange([...value, opt.value])
    }
  }

  const isRowDisabled = (i: number) => {
    if (i >= filtered.length) return false
    const opt = filtered[i]
    return Boolean(opt.disabled) || (atMax && !selectedSet.has(opt.value))
  }

  const selectIndex = (index: number) => {
    if (index < filtered.length) {
      toggle(filtered[index])
      // Keep the panel open for multi-select; re-focus search.
      requestAnimationFrame(() => searchInputRef.current?.focus())
    } else if (showCreateRow) {
      onCreate?.(query.trim())
      setQuery('')
    }
  }

  const { active, setActive, handleKeyDown } = useListNavigation({
    isOpen: open,
    count: rowCount,
    onSelect: selectIndex,
    onClose: close,
    onOpen: () => setOpen(true),
    isDisabled: isRowDisabled,
  })

  const openPanel = () => {
    if (disabled) return
    setOpen(true)
    onSearchChange?.('')
    requestAnimationFrame(() => searchInputRef.current?.focus())
  }

  const handleSearch = (v: string) => {
    setQuery(v)
    onSearchChange?.(v)
  }

  const selectAll = () => {
    const pickable = filtered.filter((o) => !o.disabled).map((o) => o.value)
    const merged = Array.from(new Set([...value, ...pickable]))
    onChange(maxSelections != null ? merged.slice(0, maxSelections) : merged)
  }
  const clearAll = () => onChange([])

  const move = (index: number, dir: -1 | 1) => {
    const target = index + dir
    if (target < 0 || target >= value.length) return
    const next = [...value]
    ;[next[index], next[target]] = [next[target], next[index]]
    onChange(next)
  }

  const onDrop = (index: number) => {
    const from = dragIndex.current
    dragIndex.current = null
    if (from == null || from === index) return
    const next = [...value]
    const [moved] = next.splice(from, 1)
    next.splice(index, 0, moved)
    onChange(next)
  }

  // Flat index of an option within `filtered` (for aria + active highlighting across groups).
  const flatIndex = (opt: Option) => filtered.indexOf(opt)

  const onTriggerKeyDown = (e: KeyboardEvent) => {
    if (!open) handleKeyDown(e)
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
          className={`${triggerClass} h-auto min-h-[56px] w-full flex-wrap py-2`}
        >
          {selectedOptions.length === 0 ? (
            <span className="flex-1 truncate text-text-muted">{placeholder ?? copy.placeholder}</span>
          ) : (
            <span className="flex flex-1 flex-wrap gap-1.5">
              {selectedOptions.map((opt) => (
                <Chip
                  key={opt.value}
                  color={opt.color}
                  removeLabel={copy.remove}
                  disabled={disabled}
                  onRemove={() => onChange(value.filter((v) => v !== opt.value))}
                >
                  {optionLabel(opt, locale)}
                </Chip>
              ))}
            </span>
          )}
          {loading && <Spinner />}
          <ChevronDown size={16} aria-hidden className={`shrink-0 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
        </button>

        {name && value.map((v) => <input key={v} type="hidden" name={`${name}[]`} value={v} />)}

        {open && (
          <div className={panelClass}>
            {isSearchable && (
              <PanelSearch
                ref={searchInputRef}
                value={query}
                onChange={handleSearch}
                onKeyDown={handleKeyDown}
                placeholder={copy.searchPlaceholder}
                ariaControls={listId}
                ariaActiveDescendant={rowCount > 0 ? `${listId}-opt-${active}` : undefined}
              />
            )}

            {bulkActions && !loading && !optionsError && filtered.length > 0 && (
              <div className="flex items-center justify-between gap-2 border-b border-border px-3 py-1.5 text-xs">
                <button
                  type="button"
                  onMouseDown={(e) => {
                    e.preventDefault()
                    selectAll()
                  }}
                  disabled={atMax}
                  className="font-semibold text-brand-600 hover:text-brand-700 disabled:opacity-40"
                >
                  {copy.selectAll}
                </button>
                <span className="tnum text-text-muted">
                  {value.length}
                  {maxSelections != null ? ` / ${maxSelections}` : ''} {copy.selectedCount}
                </span>
                <button
                  type="button"
                  onMouseDown={(e) => {
                    e.preventDefault()
                    clearAll()
                  }}
                  disabled={value.length === 0}
                  className="font-semibold text-text-secondary hover:text-text-primary disabled:opacity-40"
                >
                  {copy.clearAll}
                </button>
              </div>
            )}

            <PanelState
              loading={loading}
              error={optionsError}
              isEmpty={filtered.length === 0 && !showCreateRow}
              copy={copy}
              onRetry={onRetry}
            />

            {!loading && !optionsError && (filtered.length > 0 || showCreateRow) && (
              <ul id={listId} role="listbox" aria-multiselectable aria-label={label} className="max-h-64 overflow-y-auto py-1">
                {filtered.length === 0 && !showCreateRow && (
                  <li className="px-3 py-6 text-center text-sm text-text-muted">{emptyText ?? copy.empty}</li>
                )}
                {groups.map(([groupKey, groupOptions]) => (
                  <li key={groupKey ?? '__ungrouped'}>
                    {groupKey && (
                      <div className="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wide text-text-muted">
                        {groupKey}
                      </div>
                    )}
                    <ul>
                      {groupOptions.map((opt) => {
                        const i = flatIndex(opt)
                        const isSelected = selectedSet.has(opt.value)
                        const rowDisabled = isRowDisabled(i)
                        const isActive = i === active
                        return (
                          <li
                            key={opt.value}
                            id={`${listId}-opt-${i}`}
                            role="option"
                            aria-selected={isSelected}
                            aria-disabled={rowDisabled || undefined}
                            onMouseEnter={() => !rowDisabled && setActive(i)}
                            onMouseDown={(e) => {
                              e.preventDefault()
                              selectIndex(i)
                            }}
                            className={`flex cursor-pointer items-center gap-2 px-3 py-2 text-sm ${
                              rowDisabled
                                ? 'cursor-not-allowed text-text-muted opacity-60'
                                : isActive
                                  ? 'bg-surface-hover text-text-primary'
                                  : 'text-text-secondary'
                            }`}
                          >
                            <span
                              className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-[5px] border ${
                                isSelected ? 'border-brand-600 bg-brand-600 text-white' : 'border-border-strong'
                              }`}
                              aria-hidden
                            >
                              {isSelected && <Check size={12} />}
                            </span>
                            <OptionSwatch option={opt} />
                            <span className="flex-1 truncate">{optionLabel(opt, locale)}</span>
                          </li>
                        )
                      })}
                    </ul>
                  </li>
                ))}

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

            {reorderable && value.length > 1 && (
              <ul className="max-h-40 overflow-y-auto border-t border-border py-1">
                {selectedOptions.map((opt, i) => (
                  <li
                    key={opt.value}
                    draggable
                    onDragStart={() => (dragIndex.current = i)}
                    onDragOver={(e) => e.preventDefault()}
                    onDrop={() => onDrop(i)}
                    className="flex items-center gap-2 px-3 py-1.5 text-sm text-text-secondary"
                  >
                    <GripVertical size={14} aria-hidden className="cursor-grab text-text-muted" />
                    <span className="flex-1 truncate">{optionLabel(opt, locale)}</span>
                    <button
                      type="button"
                      aria-label={copy.moveUp}
                      disabled={i === 0}
                      onMouseDown={(e) => {
                        e.preventDefault()
                        move(i, -1)
                      }}
                      className="rounded p-0.5 text-text-muted hover:text-text-primary disabled:opacity-30"
                    >
                      <ChevronUp size={14} aria-hidden />
                    </button>
                    <button
                      type="button"
                      aria-label={copy.moveDown}
                      disabled={i === value.length - 1}
                      onMouseDown={(e) => {
                        e.preventDefault()
                        move(i, 1)
                      }}
                      className="rounded p-0.5 text-text-muted hover:text-text-primary disabled:opacity-30"
                    >
                      <ChevronDown size={14} aria-hidden />
                    </button>
                  </li>
                ))}
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
