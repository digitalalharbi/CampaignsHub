import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { TabActivity } from './TabActivity'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listClientActivity: vi.fn() }))

import { listClientActivity } from './api'

/**
 * FILES-LIBRARY-001, on the sibling surface — the timeline says what it is not showing.
 *
 * Three caps stand between the reader and a client's history: two hundred audit rows, two hundred
 * request events, and a hundred after the merge. None of them reached the response, so a client
 * with four years of history showed a hundred entries and nothing said so — which reads as «this is
 * what happened» rather than «this is the most recent hundred».
 */
const entry = (id: string) => ({
  id, action: 'client.updated', actor: 'O', source: 'audit',
  time: '2026-08-01T00:00:00Z', old: null, new: null,
  related_entity: { type: 'client', id: 'c1' },
})

describe('the client activity timeline', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['clients.view'])
  })
  afterEach(() => signOut())

  it('says how much history it is not showing', async () => {
    vi.mocked(listClientActivity).mockResolvedValue({
      timeline: [entry('a'), entry('b')], timeline_total: 4_212, timeline_withheld: 4_210,
    } as never)
    renderWithProviders(<TabActivity clientId="c1" />)

    const note = await screen.findByTestId('activity-withheld')
    expect(note.textContent).toContain('4,212'.replace(',', ''))
  })

  it('says nothing when the whole history fitted', async () => {
    vi.mocked(listClientActivity).mockResolvedValue({
      timeline: [entry('a')], timeline_total: 1, timeline_withheld: 0,
    } as never)
    renderWithProviders(<TabActivity clientId="c1" />)

    /* The row renders whatever label the action maps to; its presence is what matters here. */
    expect(await screen.findByRole('listitem')).toBeInTheDocument()
    expect(screen.queryByTestId('activity-withheld')).toBeNull()
  })
})
