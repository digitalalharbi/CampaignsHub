import { useEffect, useMemo, useRef, useState } from 'react'
import { useUi } from '@/stores/ui'
import { SelectField } from './SelectField'
import { FORMS_COPY, type BaseFieldProps, type Option } from './types'
import { useDebouncedValue } from './useTypeahead'

export interface AsyncSelectProps extends BaseFieldProps {
  value: string | null
  onChange: (value: string | null) => void
  /** Server search: resolves the options for a query. Called (debounced) on open + each keystroke. */
  loadOptions: (query: string) => Promise<Option[]>
  placeholder?: string
  debounceMs?: number
  /** The option for the current value, so its label renders before/without a matching load. */
  selectedOption?: Option | null
  clearable?: boolean
  onManage?: () => void
  emptyText?: string
}

/**
 * Same UX as SelectField, but options come from an async `loadOptions(query)` promise. Input is
 * debounced and the loading/error states are surfaced in the panel. Stale responses are dropped.
 */
export function AsyncSelect({
  value,
  onChange,
  loadOptions,
  debounceMs = 300,
  selectedOption,
  ...rest
}: AsyncSelectProps) {
  const locale = useUi((s) => s.locale)
  const copy = FORMS_COPY[locale]

  const [active, setActive] = useState(false)
  const [query, setQuery] = useState('')
  const [options, setOptions] = useState<Option[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const requestId = useRef(0)

  const debounced = useDebouncedValue(query, debounceMs)

  const runLoad = (q: string) => {
    const id = ++requestId.current
    setLoading(true)
    setError(null)
    loadOptions(q)
      .then((opts) => {
        if (id === requestId.current) setOptions(opts)
      })
      .catch((e) => {
        if (id === requestId.current) setError(e instanceof Error ? e.message : copy.error)
      })
      .finally(() => {
        if (id === requestId.current) setLoading(false)
      })
  }

  // Load whenever the (debounced) query changes, once the panel has been opened at least once.
  useEffect(() => {
    if (!active) return
    runLoad(debounced)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced, active])

  // Keep the selected option present so its label shows even if not in the current result set.
  const mergedOptions = useMemo(() => {
    if (!selectedOption) return options
    return options.some((o) => o.value === selectedOption.value) ? options : [selectedOption, ...options]
  }, [options, selectedOption])

  return (
    <SelectField
      {...rest}
      value={value}
      onChange={onChange}
      options={mergedOptions}
      searchable
      loading={loading}
      optionsError={error}
      onRetry={() => runLoad(debounced)}
      onSearchChange={(q) => {
        if (!active) setActive(true)
        setQuery(q)
      }}
    />
  )
}
