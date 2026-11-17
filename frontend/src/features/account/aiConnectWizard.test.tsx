import { fireEvent, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithProviders, signInWith } from '@/test/utils'
import { AiConnectWizard } from './AiConnectWizard'

vi.mock('@/lib/api/client', () => ({
  getData: vi.fn(async () => ({ tokens: [] })),
}))

/** AI-CONNECT-EASY-001 — next, next: pick the assistant, copy the one link, paste it there, then what to ask. */
describe('AiConnectWizard', () => {
  it('walks a non-developer from the assistant to the first question, with no token anywhere', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<AiConnectWizard onClose={() => {}} />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('ai-wizard-pick-claude'))
    expect(screen.getByTestId('ai-wizard-url')).toHaveTextContent('mcp.campaignshub.io')
    // «التالي» waits for the copy, so nobody arrives at the paste step empty-handed.
    expect(screen.getByTestId('ai-wizard-next')).toBeDisabled()
    fireEvent.click(screen.getByTestId('ai-wizard-copy-url'))
    expect(await screen.findByText('تم النسخ')).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('ai-wizard-next'))

    expect(screen.getByTestId('ai-wizard-paste')).toHaveTextContent('Add custom connector')
    expect(screen.getByTestId('ai-wizard-paste')).toHaveTextContent('«سماح»')
    fireEvent.click(screen.getByTestId('ai-wizard-done'))

    expect(screen.getByTestId('ai-wizard-ask')).toHaveTextContent('كم صرفت على الإعلانات هالشهر وكم بعت في متجري')
    expect(screen.queryByText(/Bearer|token/i)).toBeNull()
  })

  it('does not offer an assistant that cannot take the link yet', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<AiConnectWizard onClose={() => {}} />, { locale: 'ar' })

    expect(await screen.findByTestId('ai-wizard-pick-gemini')).toBeDisabled()
  })
})
