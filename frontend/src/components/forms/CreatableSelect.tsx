import { useMemo, useState } from 'react'
import { useUi } from '@/stores/ui'
import { MultiSelectField } from './MultiSelectField'
import { SelectField } from './SelectField'
import { OptionDrawer } from './OptionDrawer'
import { FORMS_COPY, type BaseFieldProps, type Option, type OptionDraft } from './types'

interface CommonProps extends BaseFieldProps {
  options: Option[]
  placeholder?: string
  searchable?: boolean
  /** Injected create fn: performs the real write and resolves with the created option. */
  onCreate: (draft: OptionDraft) => Promise<Option>
  /** Parent options for hierarchical taxonomies (shown in the drawer). */
  parents?: Option[]
  showColor?: boolean
  showIcon?: boolean
  loading?: boolean
  /** A sentence this caller owns, or the raw error — classified by `PanelState`. */
  optionsError?: unknown
  onRetry?: () => void
  onManage?: () => void
}

interface SingleProps extends CommonProps {
  mode?: 'single'
  value: string | null
  onChange: (value: string | null) => void
}

interface MultiProps extends CommonProps {
  mode: 'multi'
  value: string[]
  onChange: (value: string[]) => void
  maxSelections?: number
}

export type CreatableSelectProps = SingleProps | MultiProps

/**
 * A select (single or multi) with an inline "+ add …" affordance. Choosing it opens the shared
 * OptionDrawer; on resolve the new option is merged locally and immediately selected — no full
 * refresh. The parent still owns the durable create via `onCreate`.
 */
export function CreatableSelect(props: CreatableSelectProps) {
  const locale = useUi((s) => s.locale)
  const copy = FORMS_COPY[locale]

  // Options created this session, merged ahead of any parent refresh.
  const [localOptions, setLocalOptions] = useState<Option[]>([])
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [seedLabel, setSeedLabel] = useState('')

  const mergedOptions = useMemo(() => {
    const seen = new Set(props.options.map((o) => o.value))
    return [...props.options, ...localOptions.filter((o) => !seen.has(o.value))]
  }, [props.options, localOptions])

  const openDrawer = (query: string) => {
    setSeedLabel(query)
    setDrawerOpen(true)
  }

  const handleCreated = (created: Option) => {
    setLocalOptions((prev) => (prev.some((o) => o.value === created.value) ? prev : [...prev, created]))
    if (props.mode === 'multi') {
      if (!props.value.includes(created.value)) props.onChange([...props.value, created.value])
    } else {
      props.onChange(created.value)
    }
  }

  const drawer = (
    <OptionDrawer
      open={drawerOpen}
      onClose={() => setDrawerOpen(false)}
      onSubmit={props.onCreate}
      onCreated={handleCreated}
      title={copy.addNew}
      initialLabel={seedLabel}
      parents={props.parents}
      showColor={props.showColor}
      showIcon={props.showIcon}
    />
  )

  const shared = {
    label: props.label,
    hint: props.hint,
    error: props.error,
    required: props.required,
    disabled: props.disabled,
    id: props.id,
    name: props.name,
    className: props.className,
    placeholder: props.placeholder,
    searchable: props.searchable,
    loading: props.loading,
    optionsError: props.optionsError,
    onRetry: props.onRetry,
    onManage: props.onManage,
  }

  if (props.mode === 'multi') {
    return (
      <>
        <MultiSelectField
          {...shared}
          value={props.value}
          onChange={props.onChange}
          maxSelections={props.maxSelections}
          options={mergedOptions}
          canCreate
          onCreate={openDrawer}
        />
        {drawer}
      </>
    )
  }

  return (
    <>
      <SelectField
        {...shared}
        value={props.value}
        onChange={props.onChange}
        options={mergedOptions}
        canCreate
        onCreate={openDrawer}
      />
      {drawer}
    </>
  )
}
