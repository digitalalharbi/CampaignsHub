import { SelectField, type SelectFieldProps } from './SelectField'

/** A SelectField with the search box always enabled (thin, explicit alias). */
export function SearchableSelect(props: Omit<SelectFieldProps, 'searchable'>) {
  return <SelectField {...props} searchable />
}
