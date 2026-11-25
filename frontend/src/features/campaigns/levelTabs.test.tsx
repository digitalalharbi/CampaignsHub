import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { LevelTabs } from './LevelTabs'

describe('CAMPAIGN-LEVELS-002 — the account levels strip', () => {
  it('lists accounts, campaigns, ad groups and ads, in that order, and carries the query', () => {
    render(<MemoryRouter initialEntries={['/app/campaigns?from=2026-11-01&to=2026-11-10']}><LevelTabs /></MemoryRouter>)
    const keys = ['accounts', 'campaigns', 'ad-groups', 'ads']
    const links = keys.map((k) => screen.getByTestId(`campaign-level-${k}`))
    expect(links.map((l) => l.getAttribute('href')?.split('?')[0])).toEqual(['/app/accounts', '/app/campaigns', '/app/ad-groups', '/app/ads'])
    expect(links[0].getAttribute('href')).toContain('from=2026-11-01')
    expect(links[1].getAttribute('aria-current')).toBe('page')
  })

  it('carries the way back to the simple view on every level but the campaigns page, and lands on it', () => {
    localStorage.setItem('ch-campaigns-layout', 'advanced')
    render(
      <MemoryRouter initialEntries={['/app/ad-groups?from=2026-11-01']}>
        <Routes>
          <Route path="/app/ad-groups" element={<LevelTabs />} />
          <Route path="/app/campaigns" element={<p data-testid="landed">campaigns</p>} />
        </Routes>
      </MemoryRouter>,
    )
    fireEvent.click(screen.getByTestId('levels-simple-view'))
    expect(screen.getByTestId('landed')).toBeInTheDocument()
    expect(localStorage.getItem('ch-campaigns-layout')).toBe('simple')
  })

  it('leaves the campaigns page its own toggle', () => {
    render(<MemoryRouter initialEntries={['/app/campaigns']}><LevelTabs /></MemoryRouter>)
    expect(screen.queryByTestId('levels-simple-view')).toBeNull()
  })
})
