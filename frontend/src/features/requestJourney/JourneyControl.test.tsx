import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { JourneyControl } from './JourneyControl'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, transitionJourney: vi.fn() }
})

import { transitionJourney } from './api'

describe('JourneyControl', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('enables only valid next transitions for the current stage', async () => {
    signInWith(['requests.change_status'])
    renderWithProviders(<JourneyControl requestId="r1" currentStage="submitted" />, { locale: 'en' })

    // submitted → under_review / rejected / cancelled / on_hold are offered.
    expect(screen.getByRole('button', { name: /Under review/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Rejected/i })).toBeInTheDocument()
    // paid is NOT reachable from submitted — no button for it.
    expect(screen.queryByRole('button', { name: /^Paid$/i })).not.toBeInTheDocument()
  })

  it('runs a transition through the real endpoint and reflects the new stage', async () => {
    vi.mocked(transitionJourney).mockResolvedValue({ journey_stage: 'under_review', payment_status: null })
    signInWith(['requests.change_status'])
    renderWithProviders(<JourneyControl requestId="r1" currentStage="submitted" />, { locale: 'en' })

    fireEvent.click(screen.getByRole('button', { name: /Under review/i }))
    await waitFor(() => expect(transitionJourney).toHaveBeenCalledWith('r1', 'under_review', undefined))

    // After success the current stage advances; qualified becomes reachable from under_review.
    expect(await screen.findByRole('button', { name: /Qualified/i })).toBeInTheDocument()
  })

  it('shows a permission note (no transition buttons) without change-status', () => {
    signInWith(['requests.view'])
    renderWithProviders(<JourneyControl requestId="r1" currentStage="submitted" />, { locale: 'en' })
    expect(screen.getByText(/need the change-status permission/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Under review/i })).not.toBeInTheDocument()
  })

  it('marks a terminal stage as having no further transitions', () => {
    signInWith(['requests.change_status'])
    renderWithProviders(<JourneyControl requestId="r1" currentStage="archived" />, { locale: 'en' })
    expect(screen.getByText(/Terminal stage/i)).toBeInTheDocument()
  })
})
