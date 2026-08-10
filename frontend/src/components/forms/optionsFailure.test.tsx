import { describe, expect, it } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { SelectField } from './SelectField'
import { MultiSelectField } from './MultiSelectField'

/**
 * AGENCY-PERMS-006 — the last surface the failure classification had not reached.
 *
 * Every taxonomy-backed control passed ONE sentence — «تعذّر تحميل الخيارات» — for a refusal, an
 * expired session, a deleted list and a dead server alike, beside a Retry button that on three of
 * those four could only produce the same answer again. `QueryFailure` had already settled this for
 * full-page surfaces; the dropdowns kept the old behaviour because the classification lived in a
 * component they do not render.
 */

/** An axios-shaped rejection, which is what `toApiError` reads. */
function httpError(status: number, message?: string) {
  return {
    isAxiosError: true,
    request: {},
    response: { status, data: message ? { message } : {} },
  }
}

describe('a dropdown whose options failed to load', () => {
  it('names a refusal as a refusal, and offers no Retry that could only be refused again', () => {
    renderWithProviders(
      <SelectField
        label="Status"
        value={null}
        onChange={() => {}}
        options={[]}
        optionsError={httpError(403)}
        onRetry={() => {}}
      />,
      { locale: 'en' },
    )
    fireEvent.click(screen.getByRole('combobox', { name: 'Status' }))

    expect(screen.getByTestId('options-failure')).toHaveTextContent('You do not have access to these options')
    expect(screen.queryByRole('button', { name: /Retry/i })).not.toBeInTheDocument()
  })

  it('says the session ended rather than that the options are broken', () => {
    renderWithProviders(
      <SelectField
        label="Status"
        value={null}
        onChange={() => {}}
        options={[]}
        optionsError={httpError(401)}
        onRetry={() => {}}
      />,
      { locale: 'en' },
    )
    fireEvent.click(screen.getByRole('combobox', { name: 'Status' }))

    expect(screen.getByTestId('options-failure')).toHaveTextContent('Your session has ended')
    expect(screen.queryByRole('button', { name: /Retry/i })).not.toBeInTheDocument()
  })

  it('keeps Retry for the one failure retrying can fix, and says it in Arabic on an Arabic page', () => {
    renderWithProviders(
      <MultiSelectField
        label="الوسوم"
        value={[]}
        onChange={() => {}}
        options={[]}
        optionsError={httpError(500)}
        onRetry={() => {}}
      />,
      { locale: 'ar' },
    )
    fireEvent.click(screen.getByRole('combobox', { name: 'الوسوم' }))

    expect(screen.getByTestId('options-failure')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /إعادة المحاولة/ })).toBeInTheDocument()
  })

  it('refuses in Arabic too — the classification is not an English-only path', () => {
    renderWithProviders(
      <SelectField label="الحالة" value={null} onChange={() => {}} options={[]} optionsError={httpError(403)} onRetry={() => {}} />,
      { locale: 'ar' },
    )
    fireEvent.click(screen.getByRole('combobox', { name: 'الحالة' }))

    expect(screen.getByTestId('options-failure')).toHaveTextContent('لا تملك صلاحية الاطلاع على هذه الخيارات')
    expect(screen.queryByRole('button', { name: /إعادة المحاولة/ })).not.toBeInTheDocument()
  })

  it('still prints a sentence the caller owns, verbatim', () => {
    renderWithProviders(
      <SelectField label="Status" value={null} onChange={() => {}} options={[]} optionsError="Nothing to show yet" />,
      { locale: 'en' },
    )
    fireEvent.click(screen.getByRole('combobox', { name: 'Status' }))

    expect(screen.getByTestId('options-failure')).toHaveTextContent('Nothing to show yet')
  })
})
