import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { Check, ChevronDown, ChevronLeft } from 'lucide-react'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'
import { FORMS_COPY, matchesQuery, optionLabel, type BaseFieldProps, type Option } from './types'
import { useClickOutside, useListNavigation } from './useTypeahead'
import { ClearButton, OptionSwatch, PanelSearch, PanelState, panelClass, triggerClass } from './internals'

export interface HierarchicalSelectProps extends BaseFieldProps {
  value: string | null
  onChange: (value: string | null) => void
  /** Flat option list; each option's `parentValue` wires the tree (root = null/undefined). */
  options: Option[]
  placeholder?: string
  /** Only leaf nodes (no children) may be selected; branches only expand/collapse. */
  leafOnly?: boolean
  searchable?: boolean
  clearable?: boolean
  loading?: boolean
  optionsError?: string | null
  onRetry?: () => void
  emptyText?: string
}

interface Row {
  option: Option
  depth: number
  hasChildren: boolean
  expanded: boolean
}

/** Unified hierarchical (tree) select: indented, expandable, single or leaf-only selection. */
export function HierarchicalSelect({
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
  leafOnly = false,
  searchable,
  clearable = true,
  loading,
  optionsError,
  onRetry,
  emptyText,
}: HierarchicalSelectProps) {
  const locale = useUi((s) => s.locale)
  const copy = FORMS_COPY[locale]
  const reactId = useId()
  const fieldId = id ?? reactId
  const listId = `${fieldId}-tree`

  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [expanded, setExpanded] = useState<Set<string>>(new Set())
  const searchInputRef = useRef<HTMLInputElement>(null)

  const isSearchable = searchable ?? options.length > 7
  const selected = useMemo(() => options.find((o) => o.value === value) ?? null, [options, value])

  const childrenOf = useMemo(() => {
    const map = new Map<string | null, Option[]>()
    for (const o of options) {
      const key = o.parentValue ?? null
      if (!map.has(key)) map.set(key, [])
      map.get(key)!.push(o)
    }
    return map
  }, [options])

  // When searching, keep a node if it or any descendant matches, and force-expand the path.
  const matchSet = useMemo(() => {
    const q = query.trim()
    if (!q) return null
    const keep = new Set<string>()
    const visit = (opt: Option): boolean => {
      const kids = childrenOf.get(opt.value) ?? []
      let anyChild = false
      for (const k of kids) anyChild = visit(k) || anyChild
      const self = matchesQuery(opt, q, locale)
      if (self || anyChild) keep.add(opt.value)
      return self || anyChild
    }
    for (const root of childrenOf.get(null) ?? []) visit(root)
    return keep
  }, [query, childrenOf, locale])

  // Depth-first flatten of currently-visible rows (respecting expand state / search).
  const rows = useMemo(() => {
    const out: Row[] = []
    const searching = matchSet != null
    const walk = (opts: Option[], depth: number) => {
      for (const opt of opts) {
        if (searching && !matchSet!.has(opt.value)) continue
        const kids = childrenOf.get(opt.value) ?? []
        const hasChildren = kids.length > 0
        const isExpanded = searching ? true : expanded.has(opt.value)
        out.push({ option: opt, depth, hasChildren, expanded: isExpanded })
        if (hasChildren && isExpanded) walk(kids, depth + 1)
      }
    }
    walk(childrenOf.get(null) ?? [], 0)
    return out
  }, [childrenOf, expanded, matchSet])

  const close = () => {
    setOpen(false)
    setQuery('')
  }
  const containerRef = useClickOutside<HTMLDivElement>(open, close)

  const isSelectable = (opt: Option, hasChildren: boolean) => !opt.disabled && !(leafOnly && hasChildren)

  const toggleExpand = (val: string) =>
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(val)) next.delete(val)
      else next.add(val)
      return next
    })

  const selectRow = (index: number) => {
    const row = rows[index]
    if (!row) return
    if (!isSelectable(row.option, row.hasChildren)) {
      if (row.hasChildren) toggleExpand(row.option.value)
      return
    }
    onChange(row.option.value)
    close()
  }

  const { active, setActive, handleKeyDown } = useListNavigation({
    isOpen: open,
    count: rows.length,
    onSelect: selectRow,
    onClose: close,
    onOpen: () => setOpen(true),
    isDisabled: (i) => Boolean(rows[i]?.option.disabled),
  })

  const onPanelKeyDown = (e: KeyboardEvent) => {
    const row = rows[active]
    if (row?.hasChildren && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) {
      // RTL: collapse toward the block-start; expand toward the child side.
      const expandKey = locale === 'ar' ? 'ArrowLeft' : 'ArrowRight'
      e.preventDefault()
      const shouldExpand = e.key === expandKey
      if (shouldExpand !== row.expanded) toggleExpand(row.option.value)
      return
    }
    handleKeyDown(e)
  }

  const openPanel = () => {
    if (disabled) return
    setOpen(true)
    requestAnimationFrame(() => searchInputRef.current?.focus())
  }

  return (
    <Field label={label} htmlFor={fieldId} hint={hint} error={error} required={required}>
      <div ref={containerRef} className={`relative ${className}`}>
        <button
          type="button"
          id={fieldId}
          role="combobox"
          aria-haspopup="tree"
          aria-expanded={open}
          aria-controls={listId}
          aria-invalid={error ? true : undefined}
          disabled={disabled}
          onClick={() => (open ? close() : openPanel())}
          onKeyDown={(e) => !open && handleKeyDown(e)}
          className={`${triggerClass} w-full`}
        >
          {selected && <OptionSwatch option={selected} />}
          <span className={`flex-1 truncate ${selected ? 'text-text-primary' : 'text-text-muted'}`}>
            {selected ? optionLabel(selected, locale) : placeholder ?? copy.placeholder}
          </span>
          {clearable && selected && !disabled && <ClearButton label={copy.clear} onClear={() => onChange(null)} />}
          <ChevronDown size={16} aria-hidden className={`shrink-0 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
        </button>

        {name && <input type="hidden" name={name} value={value ?? ''} />}

        {open && (
          <div className={panelClass}>
            {isSearchable && (
              <PanelSearch
                ref={searchInputRef}
                value={query}
                onChange={setQuery}
                onKeyDown={onPanelKeyDown}
                placeholder={copy.searchPlaceholder}
                ariaControls={listId}
                ariaActiveDescendant={rows.length > 0 ? `${listId}-row-${active}` : undefined}
              />
            )}

            <PanelState loading={loading} error={optionsError} isEmpty={rows.length === 0} copy={copy} onRetry={onRetry} />

            {!loading && !optionsError && rows.length > 0 && (
              <ul id={listId} role="tree" aria-label={label} className="max-h-64 overflow-y-auto py-1">
                {rows.map((row, i) => {
                  const { option, depth, hasChildren, expanded: isExpanded } = row
                  const selectable = isSelectable(option, hasChildren)
                  const isSelected = option.value === value
                  const isActive = i === active
                  return (
                    <li
                      key={option.value}
                      id={`${listId}-row-${i}`}
                      role="treeitem"
                      aria-selected={isSelected}
                      aria-expanded={hasChildren ? isExpanded : undefined}
                      aria-disabled={option.disabled || undefined}
                      onMouseEnter={() => !option.disabled && setActive(i)}
                      onMouseDown={(e) => {
                        e.preventDefault()
                        selectRow(i)
                      }}
                      style={{ paddingInlineStart: `${depth * 16 + 12}px` }}
                      className={`flex cursor-pointer items-center gap-1.5 pe-3 py-2 text-sm ${
                        option.disabled
                          ? 'cursor-not-allowed text-text-muted opacity-60'
                          : isActive
                            ? 'bg-surface-hover text-text-primary'
                            : selectable
                              ? 'text-text-secondary'
                              : 'font-semibold text-text-primary'
                      }`}
                    >
                      {hasChildren ? (
                        <button
                          type="button"
                          tabIndex={-1}
                          aria-label={isExpanded ? copy.collapse : copy.expand}
                          onMouseDown={(e) => {
                            e.preventDefault()
                            e.stopPropagation()
                            toggleExpand(option.value)
                          }}
                          className="rounded p-0.5 text-text-muted hover:text-text-primary"
                        >
                          <ChevronLeft
                            size={14}
                            aria-hidden
                            className={`transition-transform ${isExpanded ? '-rotate-90' : locale === 'ar' ? '' : 'rotate-180'}`}
                          />
                        </button>
                      ) : (
                        <span className="w-[18px] shrink-0" aria-hidden />
                      )}
                      <OptionSwatch option={option} />
                      <span className="flex-1 truncate">{optionLabel(option, locale)}</span>
                      {isSelected && <Check size={15} aria-hidden className="shrink-0 text-brand-600" />}
                    </li>
                  )
                })}
              </ul>
            )}

            {!loading && !optionsError && rows.length === 0 && emptyText && (
              <div className="px-3 py-6 text-center text-sm text-text-muted">{emptyText}</div>
            )}
          </div>
        )}
      </div>
    </Field>
  )
}
