import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import type { Locale } from '@/stores/ui'
import { filterOptions, type Option } from './types'

/** Debounce any changing value (used by AsyncSelect for server search). */
export function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delayMs)
    return () => clearTimeout(id)
  }, [value, delayMs])
  return debounced
}

/** Close a popover when a pointer/focus lands outside `ref`. */
export function useClickOutside<T extends HTMLElement>(active: boolean, onOutside: () => void) {
  const ref = useRef<T>(null)
  useEffect(() => {
    if (!active) return
    const handler = (e: Event) => {
      if (ref.current && !ref.current.contains(e.target as Node)) onOutside()
    }
    document.addEventListener('mousedown', handler)
    document.addEventListener('focusin', handler)
    return () => {
      document.removeEventListener('mousedown', handler)
      document.removeEventListener('focusin', handler)
    }
  }, [active, onOutside])
  return ref
}

/** Local text filtering over an option list. */
export function useTypeahead(options: Option[], locale: Locale) {
  const [query, setQuery] = useState('')
  const filtered = useMemo(() => filterOptions(options, query, locale), [options, query, locale])
  return { query, setQuery, filtered }
}

interface NavOptions {
  isOpen: boolean
  count: number
  onSelect: (index: number) => void
  onClose: () => void
  onOpen: () => void
  /** Rows that cannot be activated (disabled options) are skipped during arrow nav. */
  isDisabled?: (index: number) => boolean
}

/**
 * Roving keyboard navigation for a listbox: ArrowUp/Down move an active descendant,
 * Enter selects it, Escape closes, Home/End jump. Disabled rows are skipped.
 */
export function useListNavigation({ isOpen, count, onSelect, onClose, onOpen, isDisabled }: NavOptions) {
  const [active, setActive] = useState(0)

  // Reset the active row whenever the list is re-opened or its length changes.
  useEffect(() => {
    if (isOpen) setActive(0)
  }, [isOpen, count])

  const step = useCallback(
    (from: number, dir: 1 | -1): number => {
      if (count === 0) return from
      let next = from
      for (let i = 0; i < count; i++) {
        next = (next + dir + count) % count
        if (!isDisabled?.(next)) return next
      }
      return from
    },
    [count, isDisabled],
  )

  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (!isOpen) {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          onOpen()
        }
        return
      }
      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault()
          setActive((a) => step(a, 1))
          break
        case 'ArrowUp':
          e.preventDefault()
          setActive((a) => step(a, -1))
          break
        case 'Home':
          e.preventDefault()
          setActive(step(-1, 1))
          break
        case 'End':
          e.preventDefault()
          setActive(step(0, -1))
          break
        case 'Enter':
          e.preventDefault()
          if (count > 0 && !isDisabled?.(active)) onSelect(active)
          break
        case 'Escape':
          e.preventDefault()
          onClose()
          break
        case 'Tab':
          onClose()
          break
      }
    },
    [isOpen, count, active, step, onSelect, onClose, onOpen, isDisabled],
  )

  return { active, setActive, handleKeyDown }
}
