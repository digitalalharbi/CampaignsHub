import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { ExternalRecipientsPanel } from './ExternalRecipientsPanel'

describe('ExternalRecipientsPanel — moved to the workspace notification settings (NOTIF-V2-SETTINGS-UI-001)', () => {
  it('is one line pointing at the external section, in both languages', () => {
    renderWithProviders(<ExternalRecipientsPanel ar />)
    expect(screen.getByTestId('external-recipients').textContent).toContain('انتقلوا')
    expect(screen.getByTestId('external-recipients-link').getAttribute('href')).toContain('tab=notifications&section=external')
  })

  it('reads in English too and offers no form', () => {
    renderWithProviders(<ExternalRecipientsPanel ar={false} />)
    expect(screen.getByTestId('external-recipients').textContent).toContain('moved')
    expect(screen.queryByRole('textbox')).toBeNull()
  })
})
