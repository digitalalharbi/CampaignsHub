import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * Draft autosave for long/multi-step forms (T10 forms overhaul).
 *
 * Persists a form's in-progress values to localStorage under a stable key and restores them on mount, so a
 * refresh or accidental navigation never loses answers. Debounced writes; a `clear()` to call on successful
 * submit. Safe when storage is unavailable (private mode / SSR) — it degrades to in-memory only.
 */
export interface FormDraft<T> {
  value: T
  setValue: (next: T | ((prev: T) => T)) => void
  /** True when a restorable draft was loaded from storage on mount. */
  restored: boolean
  /** Remove the persisted draft (call after a successful submit). */
  clear: () => void
}

function readDraft<T>(key: string): T | undefined {
  try {
    const raw = window.localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as T) : undefined
  } catch {
    return undefined
  }
}

export function useFormDraft<T>(key: string, initial: T, { debounceMs = 400 }: { debounceMs?: number } = {}): FormDraft<T> {
  const storageKey = `chub:draft:${key}`
  const restoredRef = useRef(false)
  const [value, setValue] = useState<T>(() => {
    const saved = readDraft<T>(storageKey)
    if (saved !== undefined) restoredRef.current = true
    return saved ?? initial
  })

  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  useEffect(() => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      try {
        window.localStorage.setItem(storageKey, JSON.stringify(value))
      } catch {
        /* storage unavailable — keep in-memory only */
      }
    }, debounceMs)
    return () => {
      if (timer.current) clearTimeout(timer.current)
    }
  }, [storageKey, value, debounceMs])

  const clear = useCallback(() => {
    try {
      window.localStorage.removeItem(storageKey)
    } catch {
      /* ignore */
    }
  }, [storageKey])

  return { value, setValue, restored: restoredRef.current, clear }
}
