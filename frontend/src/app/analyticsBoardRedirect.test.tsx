import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { HomeToDashboard } from './legacyRedirects'
import { agencyNavLeafPaths } from '@/layouts/agencyNav'
import { appNavLeafPaths } from '@/layouts/appNav'

/**
 * DASHBOARD-PATH-001 — Owner 2026-11-20: the landing is `/…/dashboard`. The old `/…/home` keeps working for bookmarks,
 * carrying its query and hash, and the rail's first leaf is the dashboard.
 */
function Where() {
  const l = useLocation()

  return <p data-testid="where">{`${l.pathname}${l.search}${l.hash}`}</p>
}

function at(route: string, portal: 'app' | 'agency') {
  render(
    <MemoryRouter initialEntries={[route]}>
      <Routes>
        <Route path={`/${portal}/home`} element={<HomeToDashboard portal={portal} />} />
        <Route path={`/${portal}/dashboard`} element={<Where />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('the old home address', () => {
  it('redirects the advertiser portal and keeps the query and the hash', () => {
    at('/app/home?project=p1#x', 'app')
    expect(screen.getByTestId('where').textContent).toBe('/app/dashboard?project=p1#x')
  })

  it('redirects the agency portal the same way', () => {
    at('/agency/home?client=c9', 'agency')
    expect(screen.getByTestId('where').textContent).toBe('/agency/dashboard?client=c9')
  })

  it('the rail opens on the dashboard, and still offers «التحليلات»', () => {
    expect(appNavLeafPaths).toContain('/app/dashboard')
    expect(agencyNavLeafPaths).toContain('/agency/dashboard')
    expect(appNavLeafPaths).toContain('/app/analytics')
    expect(agencyNavLeafPaths).toContain('/agency/analytics')
    expect(appNavLeafPaths).not.toContain('/app/home')
  })
})
