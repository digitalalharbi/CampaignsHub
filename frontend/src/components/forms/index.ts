/**
 * Unified, props-driven form-control library. Pure components (no page logic, no hardcoded
 * options) — the data/API layer (see src/features/taxonomy) is separate. Arabic-first, RTL,
 * light/dark, keyboard-accessible.
 */
export type { Option, OptionDraft, BaseFieldProps, FormsCopy } from './types'
export { optionLabel, matchesQuery, filterOptions, FORMS_COPY } from './types'

export { useTypeahead, useListNavigation, useDebouncedValue, useClickOutside } from './useTypeahead'

export { SelectField, type SelectFieldProps } from './SelectField'
export { SearchableSelect } from './SearchableSelect'
export { MultiSelectField, type MultiSelectFieldProps } from './MultiSelectField'
export { CreatableSelect, type CreatableSelectProps } from './CreatableSelect'
export { HierarchicalSelect, type HierarchicalSelectProps } from './HierarchicalSelect'
export { AsyncSelect, type AsyncSelectProps } from './AsyncSelect'
export { TagInput, type TagInputProps } from './TagInput'
export { RadioCardGroup, type RadioCardGroupProps, type RadioCardOption } from './RadioCardGroup'
export { CheckboxGroup, type CheckboxGroupProps } from './CheckboxGroup'
export { OptionDrawer, type OptionDrawerProps } from './OptionDrawer'
