import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { Route, Routes, useLocation } from 'react-router-dom'
import { RequireInfluencersEnabled } from './RequireInfluencersEnabled'
import { features } from '@/lib/features'
import { renderWithProviders } from '@/test/utils'

/**
 * The influencers portal is closed, and it did not become a dead end (INFL-OFF-001).
 *
 * The claim under test is not "the link is gone". Links are the easy half. It is that the ADDRESSES
 * still answer — because people have them bookmarked, in emails and in chat histories — and that
 * what they answer with is a real page that says why, rather than a 404, a blank screen or a
 * "coming soon" card.
 */
describe('the retired influencers portal', () => {
  it('ships switched off', () => {
    expect(features.influencersUgc).toBe(false)
  })

  it('sends a retired address to the services catalogue, saying why', () => {
    // The router under test is in memory, so the landing route reports its OWN location rather than
    // the jsdom window's — which never moves and would make this pass for the wrong reason.
    function Landed() {
      const { pathname, search } = useLocation()
      return <p>{`landed:${pathname}${search}`}</p>
    }

    renderWithProviders(
      <Routes>
        <Route path="/influencers" element={<RequireInfluencersEnabled />} />
        <Route path="/services" element={<Landed />} />
      </Routes>,
      { route: '/influencers', locale: 'en' },
    )

    // A real destination, carrying the reason the page reads to explain the arrival.
    expect(screen.getByText(/^landed:/)).toHaveTextContent('unavailable=influencers')
  })
})
