import { beforeEach, describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { MoneyWithheldNote } from './MoneyWithheldNote'
import { projectIdOf, useVisibility } from '@/stores/visibility'
import { useProject } from '@/stores/project'
import { renderWithProviders } from '@/test/utils'

/** MEMBER-VISIBILITY-001 — the note appears only for a project the server said it withheld money on. */
describe('the money-withheld note', () => {
  beforeEach(() => { useVisibility.getState().clear(); useProject.getState().setCurrentProjectId('11111111-1111-1111-1111-111111111111') })

  it('is absent until a project-scoped answer says money_withheld, then names it for that project only', () => {
    const { rerender } = renderWithProviders(<MoneyWithheldNote />, { locale: 'ar' })
    expect(screen.queryByTestId('money-withheld-note')).toBeNull()

    useVisibility.getState().markMoneyWithheld('22222222-2222-2222-2222-222222222222')
    rerender(<MoneyWithheldNote />)
    expect(screen.queryByTestId('money-withheld-note')).toBeNull()

    useVisibility.getState().markMoneyWithheld('11111111-1111-1111-1111-111111111111')
    rerender(<MoneyWithheldNote />)
    expect(screen.getByTestId('money-withheld-note')).toHaveTextContent('محجوبة عنك')
  })

  it('reads the project off a scoped URL and nothing off an unscoped one', () => {
    expect(projectIdOf('/projects/11111111-1111-1111-1111-111111111111/metrics/summary?from=x')).toBe('11111111-1111-1111-1111-111111111111')
    expect(projectIdOf('/short-links')).toBeNull()
    expect(projectIdOf(undefined)).toBeNull()
  })
})
