import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
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
})
